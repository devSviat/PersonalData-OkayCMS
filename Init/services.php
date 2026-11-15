<?php

use Okay\Core\BackendTranslations;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\EntityMigrator;
use Okay\Core\Modules\Modules;
use Okay\Core\QueryFactory;
use Okay\Core\Request;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Core\OkayContainer\Reference\ParameterReference as PR;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Settings;
use Okay\Entities\CallbacksEntity;
use Okay\Entities\CommentsEntity;
use Okay\Entities\FeedbacksEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\UsersEntity;
use Okay\Modules\Sviat\InformBackInStock\Entities\InformBackInStockEntity;
use Okay\Modules\Sviat\PartsSelection\Entities\PartsSelectionEntity;
use Okay\Modules\Sviat\OrderReturns\Entities\OrderReturnEntity;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\BackendSettingsHelper;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentBadges;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentLogHelper;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentPolicy;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Consent\RequestState;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Extensions\AnonymizeExtension;
use Okay\Modules\Sviat\PersonalData\Extensions\LinkExtension;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CallbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CartAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CommentAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FastOrderAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FeedbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\RegisterAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\CommentType;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Forms\RecordLookup;
use Okay\Modules\Sviat\PersonalData\Forms\RecordProbe;
use Okay\Modules\Sviat\PersonalData\Init\SchemaMigration;
use Okay\Modules\Sviat\PersonalData\Integration\Consent;
use Okay\Modules\Sviat\PersonalData\Scheduler\PurgeTask;
use Okay\Modules\Sviat\PersonalData\Policy\PolicyPage;
use Psr\Log\LoggerInterface;

// Кнопка відправки: input type="submit" або button без type="button|reset"; прихована aria-hidden не рахується.
// Значення атрибутів на кшталт {$lang->…} містять «>», тож вираз не спирається на [^>]*.
$submitButton = static fn(string $marker): string =>
    '(?s)^<(?:input\b(?=.*type="submit")|button\b(?!.*type="(?:button|reset)"))(?=.*' . $marker . ')(?!.*aria-hidden)';
$designBlock = static fn(string $form): string => 'get_design_block block="sviat_personal_data_' . $form . '"';
// Повтор підписки нового запису не створює: модуль знаходить наявний за variant_id та email із POST
// (InformBackInStockEntity::getRec), тож і запис має збігтися з POST за обома полями.
$sameSubscription = static function (object $record, Request $request): bool {
    $email = $request->post('email');
    $variantId = (int) $request->post('variant_id', 'integer');

    return is_string($email) && trim($email) !== '' && $variantId > 0
        && (int) ($record->variant_id ?? 0) === $variantId
        && is_string($record->email ?? null)
        && mb_strtolower(trim($record->email)) === mb_strtolower(trim($email));
};

$forms = [
    new FormDescriptor('cart', 'orders', ['name', 'phone', 'email', 'address', 'comment'],
        'cart.tpl', $submitButton('name="checkout"'), 'order', OrdersEntity::class, true, null, false),
    new FormDescriptor('register', 'account', ['name', 'email', 'phone', 'password'],
        'register.tpl', $submitButton('name="register"'), 'user', UsersEntity::class, false, null, false),
    new FormDescriptor('feedback', 'requests', ['name', 'email', 'text'],
        'feedback.tpl', $submitButton('name="feedback"'), 'feedback', FeedbacksEntity::class, true, null, false),
    new FormDescriptor('callback', 'requests', ['name', 'phone', 'comment'],
        'callback.tpl', $submitButton('name="callback"'), 'callback', CallbacksEntity::class, false, null, false),
    new FormDescriptor('comment_product', 'reviews', ['name', 'email', 'text', 'rating'],
        'product.tpl', $submitButton('name="comment"'), 'comment', CommentsEntity::class, true, null, false),
    new FormDescriptor('comment_post', 'reviews', ['name', 'email', 'text'],
        'post.tpl', $submitButton('name="comment"'), 'comment', CommentsEntity::class, true, null, false),
    new FormDescriptor('fast_order', 'orders', ['name', 'phone'],
        'fast_order_form.tpl', $submitButton('fn_fast_order_submit'), 'order', OrdersEntity::class, true, 'OkayCMS/FastOrder', false),
    new FormDescriptor('back_in_stock', 'requests', ['name', 'email'],
        'inform_stock_form.tpl', $designBlock('back_in_stock'), 'back_in_stock',
        InformBackInStockEntity::class, false, 'Sviat/InformBackInStock', true, $sameSubscription),
    new FormDescriptor('parts_selection', 'requests', ['name', 'phone', 'device', 'text', 'photo'],
        'parts_selection_form.tpl', $designBlock('parts_selection'), 'parts_selection',
        PartsSelectionEntity::class, false, 'Sviat/PartsSelection', true),
    new FormDescriptor('order_return', 'requests', ['name', 'tax_id', 'iban', 'phone', 'email', 'order', 'text', 'photo'],
        'order_return_form.tpl', $designBlock('order_return'), 'order_return',
        OrderReturnEntity::class, false, 'Sviat/OrderReturns', true),
    new FormDescriptor('review_request', 'reviews', ['name', 'text', 'rating'],
        'review_request.tpl', $designBlock('review_request'), 'comment', CommentsEntity::class, true, 'Sviat/ReviewRequests', true),
];

return [
    FormRegistry::class => [
        'class' => FormRegistry::class,
        'arguments' => [
            new SR(Modules::class),
            ...$forms,
        ],
    ],
    FormSettings::class => [
        'class' => FormSettings::class,
        'arguments' => [
            new SR(Settings::class),
        ],
    ],
    CommentType::class => [
        'class' => CommentType::class,
    ],
    SchemaState::class => [
        'class' => SchemaState::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(Database::class),
            new SR(QueryFactory::class),
            new SR(Config::class),
            new SR(EntityFactory::class),
            new SR(LoggerInterface::class),
            new PR('root_dir'),
        ],
    ],
    RecordLookup::class => [
        'class' => RecordLookup::class,
        'arguments' => [
            new SR(RecordProbe::class),
            new SR(EntityFactory::class),
        ],
    ],
    RecordProbe::class => [
        'class' => RecordProbe::class,
        'arguments' => [
            new SR(Database::class),
            new SR(QueryFactory::class),
        ],
    ],
    PolicyPage::class => [
        'class' => PolicyPage::class,
        'arguments' => [
            new SR(FormSettings::class),
            new SR(EntityFactory::class),
        ],
    ],
    SchemaMigration::class => [
        'class' => SchemaMigration::class,
        'arguments' => [
            new SR(EntityMigrator::class),
            new SR(SchemaState::class),
            new SR(Settings::class),
            new SR(FormRegistry::class),
            new SR(FormSettings::class),
            new SR(LoggerInterface::class),
            new PR('root_dir'),
        ],
    ],
    RenderProof::class => [
        'class' => RenderProof::class,
    ],
    RequestState::class => [
        'class' => RequestState::class,
    ],
    ConsentPolicy::class => [
        'class' => ConsentPolicy::class,
        'arguments' => [
            new SR(SchemaState::class),
            new SR(FormRegistry::class),
            new SR(FormSettings::class),
            new SR(RenderProof::class),
        ],
    ],
    ErrorText::class => [
        'class' => ErrorText::class,
        'arguments' => [
            new SR(FrontTranslations::class),
        ],
    ],
    ConsentLedger::class => [
        'class' => ConsentLedger::class,
        'arguments' => [
            new SR(FormRegistry::class),
            new SR(RecordProbe::class),
            new SR(RenderProof::class),
            new SR(Languages::class),
            new SR(PolicyPage::class),
            new SR(LoggerInterface::class),
        ],
    ],
    ConsentRecorder::class => [
        'class' => ConsentRecorder::class,
        'arguments' => [
            new SR(ConsentLedger::class),
            new SR(FormRegistry::class),
            new SR(EntityFactory::class),
            new SR(LoggerInterface::class),
            new SR(Request::class),
        ],
    ],
    Consent::class => [
        'class' => Consent::class,
        'arguments' => [
            new SR(SchemaState::class),
            new SR(FormRegistry::class),
            new SR(ConsentGate::class),
            new SR(ConsentRecorder::class),
            new SR(LoggerInterface::class),
        ],
    ],
    LinkExtension::class => [
        'class' => LinkExtension::class,
        'arguments' => [
            new SR(ConsentLedger::class),
            new SR(ConsentRecorder::class),
            new SR(FormSettings::class),
            new SR(FormRegistry::class),
            new SR(CommentType::class),
        ],
    ],
    AnonymizeExtension::class => [
        'class' => AnonymizeExtension::class,
        'arguments' => [
            new SR(ConsentRecorder::class),
            new SR(FormRegistry::class),
            new SR(RecordLookup::class),
            new SR(LoggerInterface::class),
        ],
    ],
    PurgeTask::class => [
        'class' => PurgeTask::class,
        'arguments' => [
            new SR(FormSettings::class),
            new SR(EntityFactory::class),
            new SR(LoggerInterface::class),
        ],
    ],
    ConsentGate::class => [
        'class' => ConsentGate::class,
        'arguments' => [
            new SR(ConsentPolicy::class),
            new SR(RequestState::class),
            // ConsentSink шлюзу — той самий екземпляр журналу, що й у швів прив'язки: квитки живуть у ньому.
            // Контейнер не має псевдонімів, тож окремий запис ConsentSink створив би другий журнал.
            new SR(ConsentLedger::class),
            new SR(Request::class),
            new SR(ErrorText::class),
        ],
    ],
    CartAdapter::class => [
        'class' => CartAdapter::class,
        'arguments' => [
            new SR(ConsentGate::class),
            new SR(FormSettings::class),
        ],
    ],
    RegisterAdapter::class => [
        'class' => RegisterAdapter::class,
        'arguments' => [
            new SR(ConsentGate::class),
            new SR(FormSettings::class),
        ],
    ],
    FeedbackAdapter::class => [
        'class' => FeedbackAdapter::class,
        'arguments' => [
            new SR(ConsentGate::class),
            new SR(FormSettings::class),
        ],
    ],
    CallbackAdapter::class => [
        'class' => CallbackAdapter::class,
        'arguments' => [
            new SR(ConsentGate::class),
            new SR(FormSettings::class),
        ],
    ],
    CommentAdapter::class => [
        'class' => CommentAdapter::class,
        'arguments' => [
            new SR(ConsentGate::class),
            new SR(FormSettings::class),
            new SR(CommentType::class),
        ],
    ],
    FastOrderAdapter::class => [
        'class' => FastOrderAdapter::class,
        'arguments' => [
            new SR(ConsentGate::class),
            new SR(FormSettings::class),
            new SR(FormRegistry::class),
        ],
    ],
    BackendSettingsHelper::class => [
        'class' => BackendSettingsHelper::class,
        'arguments' => [
            new SR(Request::class),
            new SR(FormRegistry::class),
            new SR(FormSettings::class),
            new SR(EntityFactory::class),
            new SR(BackendTranslations::class),
        ],
    ],
    ConsentLogHelper::class => [
        'class' => ConsentLogHelper::class,
        'arguments' => [
            new SR(Request::class),
            new SR(FormRegistry::class),
            new SR(EntityFactory::class),
            new SR(Languages::class),
            new SR(BackendTranslations::class),
            new SR(ConsentRecorder::class),
            new SR(LoggerInterface::class),
        ],
    ],
    ConsentBadges::class => [
        'class' => ConsentBadges::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(FormRegistry::class),
        ],
    ],
];

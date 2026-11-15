<?php


namespace Okay\Modules\Sviat\PersonalData\Init;

use Okay\Core\Design;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Entities\CallbacksEntity;
use Okay\Entities\CommentsEntity;
use Okay\Entities\FeedbacksEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\UsersEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\UserHelper;
use Okay\Helpers\ValidateHelper;
use Okay\Core\Modules\AbstractInit;
use Okay\Modules\OkayCMS\FastOrder\Extenders\BackendExtender as FastOrderValidation;
use Okay\Modules\Sviat\InformBackInStock\Entities\InformBackInStockEntity;
use Okay\Modules\Sviat\OrderReturns\Entities\OrderReturnEntity;
use Okay\Modules\Sviat\PartsSelection\Entities\PartsSelectionEntity;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentBadges;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Extensions\AnonymizeExtension;
use Okay\Modules\Sviat\PersonalData\Extensions\LinkExtension;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CallbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CartAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CommentAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FastOrderAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FeedbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\RegisterAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Scheduler\PurgeTask;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('DescriptionAdmin');
    }

    /**
     * Таблиці 1.1.0 з'являються лише тут: install() їх не створює, ядро на чистому встановленні кличе всі update_*.
     * Сервіси модуля Installer прив'язує перед install() і update_* через Modules::bindModuleServices().
     */
    public function update_1_1_0(): void
    {
        ServiceLocator::getInstance()->getService(SchemaMigration::class)->run();
    }

    public function init()
    {
        $this->addPermission('sviat__personal_data');
        $this->addPermission('sviat__personal_data__log');
        $this->registerBackendController('DescriptionAdmin');
        $this->addBackendControllerPermission('DescriptionAdmin', 'sviat__personal_data');
        // Пункт меню дає праву назву у формі прав менеджера: без нього ядро підписує право сирим ключем.
        $this->extendBackendMenu('left_users', [
            'sviat__personal_data__menu_forms' => ['DescriptionAdmin'],
        ]);

        // Старі скомпільовані шаблони можуть не намалювати чекбокс, тож до «Оновити» згода не діє ніде.
        $schemaState = $this->schemaState();
        if (!$schemaState->isMigrated()) {
            $schemaState->warnNotMigrated();
            return;
        }

        $this->registerSchedule(
            new Schedule([PurgeTask::class, 'run'])
                ->name('Sviat/PersonalData: consent retention purge')
                ->time('47 3 * * *')
                ->overlap(false)
                ->timeout(600)
        );

        $this->registerBackendController('ConsentLogAdmin');
        $this->addBackendControllerPermission('ConsentLogAdmin', 'sviat__personal_data__log');
        $this->extendBackendMenu('left_users', [
            'sviat__personal_data__menu_log' => ['ConsentLogAdmin'],
        ]);
        $this->registerConsentBadges();

        $locator = ServiceLocator::getInstance();

        if ($locator->getService(CartAdapter::class)->isActive()) {
            // Не в ланцюжку контактів: там модуль, що робить email необов'язковим, знімає помилку, і згода,
            // яка чекає на порожню помилку, залежала б від порядку ланок. Помилку цієї перевірки ніхто не знімає.
            $this->registerChainExtension(
                [ValidateHelper::class, 'getCartSecurityError'],
                [CartAdapter::class,    'extendGetCartSecurityError']);
        }

        if ($locator->getService(RegisterAdapter::class)->isActive()) {
            $this->registerChainExtension(
                [ValidateHelper::class,   'getUserRegisterError'],
                [RegisterAdapter::class, 'extendGetUserRegisterError']);
        }

        if ($locator->getService(FeedbackAdapter::class)->isActive()) {
            $this->registerChainExtension(
                [ValidateHelper::class,   'getFeedbackValidateError'],
                [FeedbackAdapter::class, 'extendGetFeedbackValidateError']);
        }

        if ($locator->getService(CallbackAdapter::class)->isActive()) {
            $this->registerChainExtension(
                [ValidateHelper::class,   'getCallbackValidateError'],
                [CallbackAdapter::class, 'extendGetCallbackValidateError']);
        }

        if ($locator->getService(CommentAdapter::class)->isActive()) {
            $this->registerChainExtension(
                [ValidateHelper::class,  'getCommentValidateError'],
                [CommentAdapter::class, 'extendGetCommentValidateError']);
        }

        if ($locator->getService(FastOrderAdapter::class)->isActive()) {
            $this->registerChainExtension(
                [FastOrderValidation::class, 'ValidateFastOrder'],
                [FastOrderAdapter::class,    'extendValidateFastOrder']);
        }

        $this->registerLinkSeams($locator->getService(LinkExtension::class));
        $this->registerAnonymizeSeams();
        $this->registerIntegrationBlocks($locator->getService(FormRegistry::class));
    }

    /**
     * Чекбокс для форм модулів, що кличуть Integration\Consent: модуль друкує `{get_design_block}` зі своєю назвою.
     * Без PersonalData блок порожній, і шаблон модуля не має невідомого тегу.
     */
    private function registerIntegrationBlocks(FormRegistry $forms): void
    {
        foreach ($forms->all() as $form) {
            if ($form->integration) {
                $this->addFrontBlock('sviat_personal_data_' . $form->key, 'consent_' . $form->key . '.tpl');
            }
        }
    }

    /** Шви прив'язки: квиток цього запиту стає рядком згоди, коли ядро вже створило запис. */
    private function registerLinkSeams(LinkExtension $link): void
    {
        if ($link->isActive('cart') || $link->isActive('fast_order')) {
            $this->registerQueueExtension(
                [OrdersHelper::class,  'finalCreateOrderProcedure'],
                [LinkExtension::class, 'linkOrder']);
        }

        if ($link->isActive('register')) {
            $this->registerQueueExtension(
                [UserHelper::class,    'register'],
                [LinkExtension::class, 'linkUser']);
        }

        if ($link->isActive('feedback')) {
            $this->registerQueueExtension(
                [FeedbacksEntity::class, 'add'],
                [LinkExtension::class,   'linkFeedback']);
        }

        if ($link->isActive('callback')) {
            $this->registerQueueExtension(
                [CallbacksEntity::class, 'add'],
                [LinkExtension::class,   'linkCallback']);
        }

        if ($link->isActive('comment_product') || $link->isActive('comment_post')) {
            $this->registerQueueExtension(
                [CommentsEntity::class, 'add'],
                [LinkExtension::class,  'linkComment']);
        }
    }

    /** Бейдж згоди на сторінках записів: змінні шаблону ядро вже присвоїло, список дає ids для однієї вибірки. */
    private function registerConsentBadges(): void
    {
        $blocks = [
            'order_contact' => ['order', 'order', null],
            'user_fields_2' => ['user', 'user', null],
            'feedbacks_item' => ['feedback', 'feedback', 'feedbacks'],
            'callbacks_item' => ['callback', 'callback', 'callbacks'],
            'comments_comment_info' => ['comment', 'comment', 'comments'],
            'inform_back_in_stock_item' => ['back_in_stock', 'record', 'records'],
            'parts_selection_item' => ['parts_selection', 'ps', 'partsSelections'],
            'order_returns_item' => ['order_return', 'or', 'orderReturns'],
        ];
        foreach ($blocks as $block => [$type, $recordVar, $listVar]) {
            $this->addBackendBlock($block, 'consent_badge.tpl', static function (Design $design, ConsentBadges $badges) use ($type, $recordVar, $listVar): void {
                $badges->assign($design, $type, $recordVar, $listVar);
            });
        }
    }

    /** Шви знеособлення не залежать від стану форми: згоди лишаються й після її вимкнення. */
    private function registerAnonymizeSeams(): void
    {
        $this->registerQueueExtension([OrdersEntity::class, 'delete'], [AnonymizeExtension::class, 'ordersDeleted']);
        $this->registerQueueExtension([UsersEntity::class, 'delete'], [AnonymizeExtension::class, 'usersDeleted']);
        $this->registerQueueExtension([FeedbacksEntity::class, 'delete'], [AnonymizeExtension::class, 'feedbacksDeleted']);
        $this->registerQueueExtension([CallbacksEntity::class, 'delete'], [AnonymizeExtension::class, 'callbacksDeleted']);
        $this->registerQueueExtension([CommentsEntity::class, 'delete'], [AnonymizeExtension::class, 'commentsDeleted']);

        // Модулів власника може не бути в системі, а ядро перевіряє, що метод сутності існує.
        if (class_exists(InformBackInStockEntity::class)) {
            $this->registerQueueExtension([InformBackInStockEntity::class, 'delete'], [AnonymizeExtension::class, 'backInStockDeleted']);
        }
        if (class_exists(PartsSelectionEntity::class)) {
            $this->registerQueueExtension([PartsSelectionEntity::class, 'delete'], [AnonymizeExtension::class, 'partsSelectionDeleted']);
        }
        if (class_exists(OrderReturnEntity::class)) {
            $this->registerQueueExtension([OrderReturnEntity::class, 'delete'], [AnonymizeExtension::class, 'orderReturnsDeleted']);
        }
    }

    #[\Override]
    public function registerSchedule(Schedule $schedule): void
    {
        if ($this->schemaState()->isMigrated()) {
            parent::registerSchedule($schedule);
        }
    }

    private function schemaState(): SchemaState
    {
        return ServiceLocator::getInstance()->getService(SchemaState::class);
    }
}

<?php

namespace Okay\Modules\Sviat\PersonalData\Extensions;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Forms\CommentType;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/**
 * Шви прив'язки форм ядра: черги після створення запису. Кожен шов знає свою форму й бере квиток лише її.
 * Без квитка шов мовчить: ці методи ядра кличуть і без згоди (відповідь менеджера, відгук через модуль).
 */
final readonly class LinkExtension implements ExtensionInterface
{
    private const array COMMENT_FORMS = ['product' => 'comment_product', 'post' => 'comment_post'];

    public function __construct(
        private ConsentLedger $ledger,
        private ConsentRecorder $recorder,
        private FormSettings $settings,
        private FormRegistry $forms,
        private CommentType $commentTypes,
    ) {}

    public function isActive(string $form): bool
    {
        return $this->settings->isEnabled($form) && $this->forms->isAvailable($form);
    }

    /** OrdersHelper::finalCreateOrderProcedure — спільний для кошика й купівлі в один клік; квиток береться один. */
    public function linkOrder(mixed $result = null, mixed $order = null): void
    {
        $form = match (true) {
            $this->ledger->has('cart') => 'cart',
            $this->ledger->has('fast_order') => 'fast_order',
            default => null,
        };
        if ($form !== null) {
            $this->recorder->link($form, is_object($order) ? ($order->id ?? null) : null);
        }
    }

    /** UserHelper::register */
    public function linkUser(mixed $userId = null): void
    {
        $this->linkIfTicket('register', $userId);
    }

    /** FeedbacksEntity::add */
    public function linkFeedback(mixed $id = null): void
    {
        $this->linkIfTicket('feedback', $id);
    }

    /** CallbacksEntity::add */
    public function linkCallback(mixed $id = null): void
    {
        $this->linkIfTicket('callback', $id);
    }

    /** CommentsEntity::add: лише відгук на товар і коментар до статті; квитків форм модулів шов не бере. */
    public function linkComment(mixed $id = null, mixed $comment = null): void
    {
        $type = is_object($comment) || is_array($comment) ? (((object) $comment)->type ?? null) : null;
        $type = $this->commentTypes->resolve(is_string($type) ? $type : null);
        if ($type !== null) {
            $this->linkIfTicket(self::COMMENT_FORMS[$type], $id);
        }
    }

    private function linkIfTicket(string $form, mixed $id): void
    {
        if ($this->ledger->has($form)) {
            $this->recorder->link($form, $id);
        }
    }
}

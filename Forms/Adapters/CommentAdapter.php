<?php

namespace Okay\Modules\Sviat\PersonalData\Forms\Adapters;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\CommentType;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/** Згода у відгуку на товар і коментарі до статті: ланка ValidateHelper::getCommentValidateError. */
final readonly class CommentAdapter implements ExtensionInterface
{
    private const array FORMS = ['product' => 'comment_product', 'post' => 'comment_post'];

    public function __construct(
        private ConsentGate $gate,
        private FormSettings $settings,
        private CommentType $types,
    ) {}

    public function isActive(): bool
    {
        return array_any(self::FORMS, $this->settings->isEnabled(...));
    }

    /** Тип об'єкта приходить від ядра; без відомого типу форма невідома, і помилка лишається як є. */
    public function extendGetCommentValidateError(?string $error, mixed $comment = null, mixed $objectType = null): ?string
    {
        $type = $this->types->resolve(is_string($objectType) ? $objectType : null);

        return $type === null ? $error : $this->gate->check($error, self::FORMS[$type]);
    }
}

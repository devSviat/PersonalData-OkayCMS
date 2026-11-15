<?php

namespace Okay\Modules\Sviat\PersonalData\Forms;

/** Що коментують: товар чи статтю. Тип передає ядро аргументом ланки. */
final readonly class CommentType
{
    private const array TYPES = ['product', 'post'];

    /** @return 'product'|'post'|null */
    public function resolve(?string $objectType): ?string
    {
        return in_array($objectType, self::TYPES, true) ? $objectType : null;
    }
}

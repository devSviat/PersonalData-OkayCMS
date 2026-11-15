<?php

namespace Modules\Sviat\PersonalData;

use Okay\Modules\Sviat\PersonalData\Forms\CommentType;
use PHPUnit\Framework\TestCase;

class CommentTypeTest extends TestCase
{
    public function testFromArgument(): void
    {
        self::assertSame('post', new CommentType()->resolve('post'));
        self::assertSame('product', new CommentType()->resolve('product'));
    }

    /** Без типу від ядра форма невідома: запасного варіанта за маршрутом немає. */
    public function testMissingArgumentIsNull(): void
    {
        self::assertNull(new CommentType()->resolve(null));
        self::assertNull(new CommentType()->resolve(''));
    }

    public function testUnknownTypeIsNull(): void
    {
        self::assertNull(new CommentType()->resolve('category'));
        self::assertNull(new CommentType()->resolve('Product'));
    }
}

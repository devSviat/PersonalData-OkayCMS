<?php

namespace Modules\Sviat\PersonalData;

use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Consent\RequestState;
use PHPUnit\Framework\TestCase;

/** Доказ показу живе в сесії між запитами; стан відмови — лише в межах запиту. */
class RenderProofTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRecordThenShown(): void
    {
        $proof = new RenderProof();
        $proof->record('callback', 2, 41);

        self::assertSame(['langId' => 2, 'policyPageId' => 41], $proof->shown('callback'));
        self::assertSame(['langId' => 2, 'policyPageId' => 41], new RenderProof()->shown('callback'), 'читається з сесії, а не з обʼєкта');
        self::assertNull($proof->shown('cart'));
    }

    public function testLaterRenderReplacesProof(): void
    {
        $proof = new RenderProof();
        $proof->record('callback', 2, 41);
        $proof->record('callback', 1, null);

        self::assertSame(['langId' => 1, 'policyPageId' => null], $proof->shown('callback'));
    }

    public function testMalformedSessionIsNoProof(): void
    {
        $proof = new RenderProof();
        foreach ([
            'scalar' => 2,
            'string lang' => ['lang' => '2', 'page' => 41],
            'zero lang' => ['lang' => 0, 'page' => 41],
            'no lang' => ['page' => 41],
            'list of the old format' => [12, 2],
        ] as $case => $value) {
            $_SESSION = ['sviat__personal_data' => ['shown' => ['callback' => $value]]];
            self::assertNull($proof->shown('callback'), $case);
        }

        $_SESSION = ['sviat__personal_data' => 'garbage'];
        self::assertNull($proof->shown('callback'));
        $proof->record('callback', 1, null);
        self::assertSame(['langId' => 1, 'policyPageId' => null], $proof->shown('callback'));

        $_SESSION = ['sviat__personal_data' => ['shown' => ['callback' => ['lang' => 1, 'page' => '41']]]];
        self::assertSame(['langId' => 1, 'policyPageId' => null], $proof->shown('callback'), 'не ціле id сторінки — null');
    }

    public function testRequestStateIsPerForm(): void
    {
        $state = new RequestState();
        self::assertFalse($state->isRefused('callback'));

        $state->refuse('callback');

        self::assertTrue($state->isRefused('callback'));
        self::assertFalse($state->isRefused('cart'));
        self::assertFalse(new RequestState()->isRefused('callback'), 'новий запит — новий стан');
        self::assertSame([], $_SESSION, 'відмова не потрапляє в сесію');
    }
}

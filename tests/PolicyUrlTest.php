<?php

namespace Modules\Sviat\PersonalData;

use Okay\Modules\Sviat\PersonalData\Policy\PolicyUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Адреса сторінки політики з роутера: лише шлях від кореня, без чужого хоста чи схеми. */
class PolicyUrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'root path' => ['/privacy'];
        yield 'root path with query' => ['/page/privacy?x=1'];
        yield 'language prefix' => ['/ru/privacy-policy'];
    }

    #[DataProvider('allowed')]
    public function testPolicyUrlWhitelistAccepts(string $url): void
    {
        self::assertTrue(PolicyUrl::validate($url), $url);
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/x'];
        yield 'https' => ['https://shop.example/privacy'];
        yield 'http' => ['http://shop.example/privacy'];
        yield 'uppercase scheme' => ['HTTPS://shop.example/privacy'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'mailto' => ['mailto:a@shop.example'];
        yield 'relative' => ['privacy.html'];
        yield 'empty' => [''];
        yield 'scheme without host' => ['https:///evil.example'];
        yield 'scheme without slashes' => ['https:evil.example'];
        yield 'userinfo' => ['https://shop.example@evil.example/'];
        yield 'leading space' => [' /privacy'];
        yield 'tab' => ["/pri\tvacy"];
        yield 'non-breaking space' => ["/pri\u{00A0}vacy"];
        yield 'line separator' => ["/pri\u{2028}vacy"];
        yield 'encoded slash start' => ['/%2fevil.example'];
        yield 'too long' => ['/' . str_repeat('a', 255)];
    }

    #[DataProvider('rejected')]
    public function testPolicyUrlWhitelistRejects(string $url): void
    {
        self::assertFalse(PolicyUrl::validate($url), $url);
    }

    public function testPolicyUrlWhitelist(): void
    {
        self::assertTrue(PolicyUrl::validate('/privacy'));
        self::assertFalse(PolicyUrl::validate('https://shop.example/privacy'));
        self::assertFalse(PolicyUrl::validate('//evil.example'));
        self::assertFalse(PolicyUrl::validate('javascript:alert(1)'));
        self::assertFalse(PolicyUrl::validate('data:text/html,x'));
    }

    /** Браузер читає `/\host` як `//host`, тобто інший хост. */
    public function testBackslashHostRejected(): void
    {
        self::assertFalse(PolicyUrl::validate('/\\evil.example'));
        self::assertFalse(PolicyUrl::validate('/ /x'));
        self::assertFalse(PolicyUrl::validate('/%0aevil'));
        self::assertFalse(PolicyUrl::validate('/%5cevil.example'));
        self::assertFalse(PolicyUrl::validate('https://shop.example\\@evil.example/'));
    }
}

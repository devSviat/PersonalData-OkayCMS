<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\Languages;
use Okay\Core\Request;
use Okay\Core\Router;
use Okay\Core\Routes\RouteFactory;
use Okay\Modules\Sviat\PersonalData\Policy\PolicyLink;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Посилання на сторінку політики будує справжній Router::generateUrl з префіксом мови, який
 * ставить справжній Languages::getLangLink. Підмінено лише список мов і таблицю роутів.
 */
class PolicyLinkTest extends TestCase
{
    /** @var array<string, mixed> статичний стан Router і Request до тесту */
    private array $saved = [];

    private Languages $languages;

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        foreach ([[Router::class, 'routes'], [Router::class, 'routeFactory'], [Router::class, 'languages'], [Request::class, 'subDir'], [Request::class, 'protocol']] as [$class, $name]) {
            $this->saved[$class . '::' . $name] = new ReflectionProperty($class, $name)->getValue();
        }

        $this->server = $_SERVER;
        // Під час generateUrl у тестах спрацьовує Request::getCurrentUrl(), а REQUEST_URI у CLI немає.
        $_SERVER['REQUEST_URI'] ??= '/';
        $this->languages = new ReflectionClass(Languages::class)->newInstanceWithoutConstructor();
        $list = [1 => (object) ['id' => 1, 'label' => 'ua'], 2 => (object) ['id' => 2, 'label' => 'en']];
        new ReflectionProperty(Languages::class, 'languagesList')->setValue($this->languages, $list);
        new ReflectionProperty(Languages::class, 'mainLanguage')->setValue($this->languages, $list[1]);

        $routeFactory = $this->createStub(RouteFactory::class);
        $routeFactory->method('create')->willReturn(false);
        new ReflectionProperty(Router::class, 'routes')->setValue(null, ['page' => ['slug' => '/{$url}']]);
        new ReflectionProperty(Router::class, 'routeFactory')->setValue(null, $routeFactory);
        new ReflectionProperty(Router::class, 'languages')->setValue(null, $this->languages);
        new ReflectionProperty(Request::class, 'subDir')->setValue(null, '');
        new ReflectionProperty(Request::class, 'protocol')->setValue(null, 'https');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        foreach ($this->saved as $key => $value) {
            [$class, $name] = explode('::', $key);
            new ReflectionProperty($class, $name)->setValue(null, $value);
        }
    }

    public function testPolicyPageLinkFollowsCurrentLanguage(): void
    {
        $pages = [(object) ['id' => 3, 'url' => 'about'], (object) ['id' => 7, 'url' => 'privacy']];

        $this->speak(1);
        self::assertSame('/privacy', PolicyLink::href(7, $pages));

        $this->speak(2);
        self::assertSame('/en/privacy', PolicyLink::href(7, $pages));
    }

    /** Немає видимої сторінки — мітка без посилання, а не бите посилання. */
    public function testNoSelectedOrVisiblePageMeansNoLink(): void
    {
        $this->speak(2);
        $pages = [(object) ['id' => 3, 'url' => 'about']];

        self::assertNull(PolicyLink::href(null, $pages), 'не вибрано');
        self::assertNull(PolicyLink::href(7, $pages), 'сторінку сховано чи видалено');
        self::assertNull(PolicyLink::href(7, null), 'дизайн не має списку сторінок');
        self::assertNull(PolicyLink::href(7, [(object) ['id' => 7, 'url' => '']]), 'сторінка без адреси');
    }

    public function testRouterFailureGivesNoLink(): void
    {
        new ReflectionProperty(Router::class, 'routes')->setValue(null, []);

        self::assertNull(PolicyLink::href(7, [(object) ['id' => 7, 'url' => 'privacy']]));
    }

    /** Слаг у базі могли змінити повз адмінку: адреса однаково проходить білий список. */
    public function testGeneratedPageHrefIsRevalidated(): void
    {
        self::assertNull(PolicyLink::href(7, [(object) ['id' => 7, 'url' => '\\evil.example']]));
        self::assertNull(PolicyLink::href(7, [(object) ['id' => 7, 'url' => 'a b']]));
    }

    private function speak(int $langId): void
    {
        new ReflectionProperty(Languages::class, 'langId')->setValue($this->languages, $langId);
    }
}

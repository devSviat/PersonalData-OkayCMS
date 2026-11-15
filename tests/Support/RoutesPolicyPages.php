<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Core\Languages;
use Okay\Core\Request;
use Okay\Core\Router;
use Okay\Core\Routes\RouteFactory;

/**
 * Роут `page` як у ядрі й справжній Languages з двома мовами (1 — головна, 2 — з префіксом): адресу сторінки
 * політики будує справжній Router::generateUrl. Для тестів на основі ModuleTestCase; тест кличе
 * restorePolicyRoutes() у tearDown().
 */
trait RoutesPolicyPages
{
    /** @var array<string, mixed> статичний стан Router і Request до тесту */
    private array $savedRouteStatics = [];

    /** @var array<string, mixed>|null */
    private ?array $savedServer = null;

    protected function routePolicyPages(): void
    {
        foreach ([[Router::class, 'routes'], [Router::class, 'routeFactory'], [Router::class, 'languages'], [Request::class, 'subDir'], [Request::class, 'protocol']] as [$class, $name]) {
            $this->savedRouteStatics[$class . '::' . $name] = new \ReflectionProperty($class, $name)->getValue();
        }
        $this->savedServer = $_SERVER;
        // Під час generateUrl спрацьовує Request::getCurrentUrl(), а REQUEST_URI у CLI немає.
        $_SERVER['REQUEST_URI'] ??= '/';
        $languages = new \ReflectionClass(Languages::class)->newInstanceWithoutConstructor();
        $list = [1 => (object) ['id' => 1, 'label' => 'ua'], 2 => (object) ['id' => 2, 'label' => 'en']];
        new \ReflectionProperty(Languages::class, 'languagesList')->setValue($languages, $list);
        new \ReflectionProperty(Languages::class, 'mainLanguage')->setValue($languages, $list[1]);
        new \ReflectionProperty(Languages::class, 'langId')->setValue($languages, 1);
        // Перший generateUrl у процесі підвантажує роути ядра, а ті беруть Languages з контейнера.
        $this->replaceServiceInContainer(Languages::class, $languages);
        $routeFactory = $this->createStub(RouteFactory::class);
        $routeFactory->method('create')->willReturn(false);
        new \ReflectionProperty(Router::class, 'routes')->setValue(null, ['page' => ['slug' => '/{$url}']]);
        new \ReflectionProperty(Router::class, 'routeFactory')->setValue(null, $routeFactory);
        new \ReflectionProperty(Router::class, 'languages')->setValue(null, $languages);
        new \ReflectionProperty(Request::class, 'subDir')->setValue(null, '');
        new \ReflectionProperty(Request::class, 'protocol')->setValue(null, 'https');
    }

    protected function restorePolicyRoutes(): void
    {
        if ($this->savedServer !== null) {
            $_SERVER = $this->savedServer;
            $this->savedServer = null;
        }
        foreach ($this->savedRouteStatics as $key => $value) {
            [$class, $name] = explode('::', $key);
            new \ReflectionProperty($class, $name)->setValue(null, $value);
        }
        $this->savedRouteStatics = [];
    }

    protected function speak(int $langId): void
    {
        new \ReflectionProperty(Languages::class, 'langId')->setValue(new \ReflectionProperty(Router::class, 'languages')->getValue(), $langId);
    }
}

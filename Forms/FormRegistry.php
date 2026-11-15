<?php

namespace Okay\Modules\Sviat\PersonalData\Forms;

use Okay\Core\Modules\Modules;

/** Усі форми, що вимагають згоду. Склад задає Init/services.php. */
final class FormRegistry
{
    /** @var array<string, FormDescriptor> */
    private array $forms = [];

    /** @var array<string, bool> `Vendor/Module` => чи активний, у межах запиту */
    private array $activeModules = [];

    public function __construct(
        private readonly Modules $modules,
        FormDescriptor ...$descriptors,
    ) {
        foreach ($descriptors as $descriptor) {
            if (isset($this->forms[$descriptor->key])) {
                throw new \InvalidArgumentException("Duplicate form \"{$descriptor->key}\"");
            }
            $this->forms[$descriptor->key] = $descriptor;
        }
    }

    /** @return list<FormDescriptor> */
    public function all(): array
    {
        return array_values($this->forms);
    }

    public function get(string $key): ?FormDescriptor
    {
        return $this->forms[$key] ?? null;
    }

    /** Форма з реєстру, чий модуль-власник (якщо він потрібен) встановлено й увімкнено. */
    public function isAvailable(string $key): bool
    {
        $form = $this->get($key);
        if ($form === null) {
            return false;
        }
        if ($form->requiredModule === null) {
            return true;
        }

        return $this->activeModules[$form->requiredModule] ??= $this->isModuleActive($form->requiredModule);
    }

    /**
     * Стан із запущених на старті запиту модулів, без запиту до бази: Modules::isActiveModule() після
     * старту модулів щоразу йде в базу, бо ModuleCache тоді вже скинуто. До старту (CLI, інсталятор)
     * модуля в списку немає, і тоді лишається запит.
     */
    private function isModuleActive(string $requiredModule): bool
    {
        $running = $this->modules->getRunningModules();
        if (is_array($running) && isset($running[$requiredModule])) {
            return (int) $running[$requiredModule]['is_active'] === 1;
        }
        [$vendor, $module] = explode('/', $requiredModule, 2);

        return (bool) $this->modules->isActiveModule($vendor, $module);
    }
}

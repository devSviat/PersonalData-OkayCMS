<?php

namespace Okay\Modules\Sviat\PersonalData\Forms;

/** Форма, що збирає персональні дані й вимагає згоду. */
final readonly class FormDescriptor
{
    public const array GROUPS = ['orders', 'account', 'requests', 'reviews'];

    /**
     * @param list<string> $collects ключі мовних рядків для підказки «збирає: …» в адмінці
     * @param string $anchor вираз `like` для TplMod: місце чекбокса в шаблоні форми
     * @param string $recordType тип запису в журналі згод
     * @param string $recordEntity клас Entity запису; модуля-власника може не бути в системі
     * @param bool $recordHasIp false: запис не має надійного IP, тож IP пишеться в рядок згоди
     * @param string|null $requiredModule `Vendor/Module`, без якого форма недоступна
     * @param bool $integration true: модуль-власник сам викликає фасад згоди
     * @param (\Closure(object, \Okay\Core\Request): bool)|null $existingMatcher чи належить наявний запис цій
     *        відправці (повтор без нового запису); отримує рядок запису й запит, true — належить
     */
    public function __construct(
        public string $key,
        public string $group,
        public array $collects,
        public string $template,
        public string $anchor,
        public string $recordType,
        public string $recordEntity,
        public bool $recordHasIp,
        public ?string $requiredModule,
        public bool $integration,
        public ?\Closure $existingMatcher = null,
    ) {
        if (!in_array($group, self::GROUPS, true)) {
            throw new \InvalidArgumentException("Unknown form group \"$group\"");
        }
    }

    public function settingKey(): string
    {
        return 'sviat__personal_data__form_' . $this->key;
    }
}

<?php

namespace Okay\Modules\Sviat\PersonalData\Init;

use Okay\Core\Modules\EntityField;
use Okay\Core\Modules\EntityMigrator;
use Okay\Core\Settings;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Psr\Log\LoggerInterface;

/**
 * Перехід 1.0.1 → 1.1.0. Кличе його лише update_1_1_0(), тобто кнопка «Оновити».
 * Повторний прогін після збою безпечний: кожен крок робить лише те, чого ще немає.
 * Ядро піднімає версію модуля лише тоді, коли метод не кинув винятку.
 */
final readonly class SchemaMigration
{
    public function __construct(
        private EntityMigrator $entityMigrator,
        private SchemaState $schemaState,
        private Settings $settings,
        private FormRegistry $forms,
        private FormSettings $formSettings,
        private LoggerInterface $logger,
        private string $rootDir,
    ) {}

    public function run(): void
    {
        $this->createTables();
        $this->migrateSettings();
        $this->verifySettings();
        $this->clearCompiledTemplates();
        $this->writeMarker();
    }

    private function createTables(): void
    {
        // createTable нічого не повертає, а Database::query ковтає помилку, тож про наявність таблиці питаємо базу.
        if ($this->schemaState->missingTables() !== []) {
            $this->entityMigrator->migrateEntityTable(ConsentsEntity::class, self::consentsFields());
        }

        $missing = $this->schemaState->missingTables();
        if ($missing !== []) {
            $this->fail('table(s) not created: ' . implode(', ', $missing));
        }
    }

    /** Ключ, що вже є в налаштуваннях, не переписується: його записав попередній прогін. */
    private function migrateSettings(): void
    {
        $old = [];
        foreach (SettingsMigration::CARRIED as $oldKey) {
            $old[$oldKey] = $this->settings->get($oldKey);
        }
        $keys = array_map(static fn($form) => $form->key, $this->forms->all());

        foreach (SettingsMigration::from101($old, $keys) as $key => $on) {
            if ($this->settings->has($this->forms->get($key)->settingKey())) {
                continue;
            }
            if ($on) {
                $this->formSettings->enable($key);
            } else {
                $this->formSettings->disable($key);
            }
        }
    }

    /**
     * Settings::set не каже, чи дійшов запис до бази. Без цієї перевірки втрачений INSERT мовчки
     * вимкнув би перенесену форму, а маркер однаково записався б.
     */
    private function verifySettings(): void
    {
        $this->settings->initSettings();
        $keys = array_map(static fn($form) => $form->settingKey(), $this->forms->all());
        $missing = array_values(array_filter(
            $keys,
            fn(string $key) => !$this->settings->has($key),
        ));
        if ($missing !== []) {
            $this->fail('settings not saved: ' . implode(', ', $missing));
        }
    }

    /**
     * Ядро після «Оновити» чистить лише тему адміна (admin_theme), а модифікації шаблонів змінилися
     * для всіх тем: без цього активна тема лишилася б зі скомпільованими шаблонами 1.0.1.
     */
    private function clearCompiledTemplates(): void
    {
        $pattern = rtrim($this->rootDir, '/') . '/compiled/*/*';
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Не кидає, як і Design::clearCompiled: файл, що лишився (зазвичай root після exec),
        // не має зривати «Оновити». Але старий шаблон активної теми не малює чекбокс, тож це видно в лозі.
        $left = count(array_filter(glob($pattern) ?: [], is_file(...)));
        if ($left > 0) {
            $this->logger->warning("Sviat/PersonalData: $left compiled template file(s) could not be removed from compiled/, the active theme may render without the consent checkbox");
        }
    }

    /** Settings::set нічого не повертає й завжди пише в пам'ять, тож маркер перевіряється перечитаною базою. */
    private function writeMarker(): void
    {
        $this->settings->set(SchemaState::MARKER_KEY, SchemaState::MARKER_VERSION);
        $this->settings->initSettings();
        if ($this->settings->get(SchemaState::MARKER_KEY) !== SchemaState::MARKER_VERSION) {
            $this->fail('schema marker was not saved');
        }
        $this->schemaState->forget();
    }

    /**
     * Ядро показує текст винятку лише у flash-повідомленні адмінки й не логує його, а VIEW замість таблиці
     * SQL-помилки не дає. Без цього рядка причина збою ніде не лишається.
     */
    private function fail(string $reason): never
    {
        $this->logger->error("Sviat/PersonalData: migration to 1.1.0 stopped: $reason");

        throw new \RuntimeException("Sviat/PersonalData: $reason");
    }

    /** @return list<EntityField> */
    private static function consentsFields(): array
    {
        $recordType = new EntityField('record_type')->setTypeVarchar(32, false);
        $recordId = new EntityField('record_id')->setTypeInt(11);

        return [
            new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            // Кілька NULL у record_id після знеособлення унікальності не порушують.
            new EntityField('form')->setTypeVarchar(32, false)->setIndexUnique(null, $recordType, $recordId),
            $recordType,
            $recordId,
            new EntityField('lang_id')->setTypeInt(11, false),
            new EntityField('given_at')->setTypeDatetime(false)->setIndex(),
            new EntityField('policy_page_id')->setTypeInt(11),
            // Стислий IPv6 не довший за 39 символів.
            new EntityField('ip')->setTypeVarchar(40),
            new EntityField('withdrawn_at')->setTypeDatetime(),
            new EntityField('withdrawn_by')->setTypeVarchar(64),
            new EntityField('anonymized_at')->setTypeDatetime()->setIndex(),
        ];
    }
}

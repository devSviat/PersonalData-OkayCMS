<?php

namespace Okay\Modules\Sviat\PersonalData\Core;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Entities\ModulesEntity;
use Psr\Log\LoggerInterface;

/**
 * Чи натиснуто «Оновити» для модуля. Доки ні, модуль нічого не реєструє й не чіпає своїх таблиць.
 * Маркер пише останнім кроком update_1_1_0(), тож він означає, що вся міграція пройшла.
 */
final class SchemaState
{
    public const string MARKER_KEY = 'sviat__personal_data__schema';
    public const string MARKER_VERSION = '1.1.0';

    public const array TABLES = [
        '__sviat__personal_data__consents',
    ];

    private const int WARNING_INTERVAL = 3600;
    private const string WARNING_STAMP = 'cache/sviat_personal_data_schema_warning';

    private ?bool $migrated = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Database $database,
        private readonly QueryFactory $queryFactory,
        private readonly Config $config,
        private readonly EntityFactory $entityFactory,
        private readonly LoggerInterface $logger,
        private readonly string $rootDir,
    ) {}

    /** Без запиту до бази: ядро вже завантажило налаштування. */
    public function isMigrated(): bool
    {
        if ($this->migrated === null) {
            $marker = $this->settings->get(self::MARKER_KEY);
            $this->migrated = is_string($marker) && version_compare($marker, self::MARKER_VERSION, '>=');
        }

        return $this->migrated;
    }

    /** Наступний isMigrated() перечитає маркер: «Оновити» записує його посеред запиту. */
    public function forget(): void
    {
        $this->migrated = null;
    }

    public function tablesExist(): bool
    {
        return $this->missingTables() === [];
    }

    /**
     * Запит до information_schema, а не пробний SELECT: помилка до відсутньої таблиці потрапила б
     * у лог. VIEW з тим самим іменем таблицею не вважається.
     *
     * @return list<string>
     */
    public function missingTables(): array
    {
        $prefix = (string) $this->config->get('db_prefix');
        $names = array_map(static fn(string $table) => $prefix . substr($table, 2), self::TABLES);
        $query = $this->queryFactory->newSqlQuery()
            ->setStatement(
                "SELECT TABLE_NAME AS name FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME IN (:names)"
            )
            ->bindValue('names', $names);

        if (!$this->database->query($query)) {
            return self::TABLES;
        }
        $found = array_map(strval(...), (array) $this->database->results('name'));

        $missing = [];
        foreach (self::TABLES as $i => $table) {
            if (!in_array($names[$i], $found, true)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /** @return array{marker: bool, version: string, tables: bool} версія — записана в модулях ядра */
    public function verifyInAdmin(): array
    {
        $module = $this->entityFactory->get(ModulesEntity::class)->getByVendorModuleName('Sviat', 'PersonalData');

        return [
            'marker' => $this->isMigrated(),
            'version' => (string) ($module->version ?? ''),
            'tables' => $this->tablesExist(),
        ];
    }

    /**
     * Адмінка показує банер «Оновити», доки немає маркера, версія в модулях ядра нижча за 1.1.0
     * або бракує таблиці. Маркер без таблиць можливий лише після ручного втручання в базу.
     */
    public function needsUpdateInAdmin(): bool
    {
        if (!$this->isMigrated()) {
            return true;
        }
        $state = $this->verifyInAdmin();

        return version_compare($state['version'], self::MARKER_VERSION, '<') || !$state['tables'];
    }

    /**
     * Технічне попередження в лог, не частіше разу на годину. Час останнього лежить у файлі, а не в базі.
     * Якщо файл не записався (наприклад, його лишив root після exec), не логує нічого: інакше
     * попередження йшло б на кожному запиті.
     */
    public function warnNotMigrated(): void
    {
        $stamp = rtrim($this->rootDir, '/') . '/' . self::WARNING_STAMP;
        if (is_file($stamp) && time() - (int) filemtime($stamp) < self::WARNING_INTERVAL) {
            return;
        }
        $writable = is_file($stamp) ? is_writable($stamp) : (is_dir(dirname($stamp)) && is_writable(dirname($stamp)));
        if (!$writable || @file_put_contents($stamp, (string) time()) === false) {
            return;
        }

        $this->logger->warning('Sviat/PersonalData: schema not migrated, press "Update" for the module in the module list');
    }
}

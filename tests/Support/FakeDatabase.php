<?php

namespace Modules\Sviat\PersonalData\Support;

use Aura\SqlQuery\QueryInterface;
use Okay\Core\Database;

/**
 * База в пам'яті для міграції: таблиця `__settings`, перелік таблиць для information_schema і MAX(id).
 * Справжній Settings працює поверх неї без змін, тож видно, що саме дійшло «до бази».
 */
final class FakeDatabase extends Database
{
    /** @var array<string, string> param => value у `__settings` */
    public array $settings = [];

    /** @var list<string> наявні таблиці з префіксом, як їх бачить information_schema */
    public array $tables = [];

    /** @var array<string, int> таблиця (як у Entity) => MAX(id) */
    public array $maxIds = [];

    public bool $tableQueryFails = false;

    public bool $maxIdQueryFails = false;

    /** INSERT у `__settings` цього параметра база відкидає */
    public ?string $rejectSettingsInsert = null;

    /** @var list<string> */
    public array $statements = [];

    /** @var (\Closure(string, array<string, mixed>): void)|null бачить кожен запит до виконання */
    public ?\Closure $onQuery = null;

    /** @var list<object> */
    private array $rows = [];

    /** @param array<string, string> $settings */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    public function __destruct() {}

    public function query(QueryInterface $query, $debug = false)
    {
        $statement = $query->getStatement();
        $bind = $query->getBindValues();
        $this->statements[] = $statement;
        $this->rows = [];
        if ($this->onQuery !== null) {
            ($this->onQuery)($statement, $bind);
        }

        if (str_contains($statement, 'information_schema')) {
            if ($this->tableQueryFails) {
                return false;
            }
            foreach (array_intersect($this->tables, (array) $bind['names']) as $name) {
                $this->rows[] = (object) ['name' => $name];
            }

            return true;
        }
        if (preg_match('~MAX\(id\).*FROM\s+(\S+)~s', $statement, $m)) {
            if ($this->maxIdQueryFails) {
                return false;
            }
            $this->rows[] = (object) ['max_id' => $this->maxIds[$m[1]] ?? null];

            return true;
        }
        if (preg_match('~^INSERT INTO `__settings`~', $statement)) {
            if ($bind['param'] === $this->rejectSettingsInsert) {
                return false;
            }
            $this->settings[$bind['param']] = $bind['value'];

            return true;
        }
        if (preg_match('~^UPDATE `__settings`~', $statement)) {
            if (isset($this->settings[$bind['param']])) {
                $this->settings[$bind['param']] = $bind['value'];
            }

            return true;
        }
        if (preg_match('~FROM\s+`__settings`~', $statement)) {
            foreach ($this->settings as $param => $value) {
                $this->rows[] = (object) ['param' => $param, 'value' => $value];
            }
        }

        return true;
    }

    public function results($field = null, $mapped = null)
    {
        return array_map(static fn(object $row) => $field === null ? $row : ($row->$field ?? null), $this->rows);
    }

    public function result($field = null)
    {
        $row = $this->rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return $field === null ? $row : ($row->$field ?? null);
    }
}

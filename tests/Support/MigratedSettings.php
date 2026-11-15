<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Core\Settings;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;

/**
 * Налаштування без бази. Типово несуть маркер оновленої схеми, бо модуль без нього нічого
 * не реєструє; `pending()` дає стан до натискання «Оновити».
 */
final class MigratedSettings extends Settings
{
    /** @var array<string, mixed> */
    public array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values + [SchemaState::MARKER_KEY => SchemaState::MARKER_VERSION];
    }

    /** @param array<string, mixed> $values */
    public static function pending(array $values = []): self
    {
        $settings = new self($values);
        unset($settings->values[SchemaState::MARKER_KEY]);

        return $settings;
    }

    public function get($param)
    {
        return $this->values[$param] ?? null;
    }

    public function has($param)
    {
        return isset($this->values[$param]);
    }

    public function set($param, $value)
    {
        $this->values[$param] = $value;
    }
}

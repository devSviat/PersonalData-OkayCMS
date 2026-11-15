<?php

namespace Okay\Modules\Sviat\PersonalData\Forms;

use Okay\Core\Settings;

/** Стан форм, строк зберігання й сторінка політики в налаштуваннях магазину. */
final readonly class FormSettings
{
    private const string PREFIX = 'sviat__personal_data__form_';
    private const string RETENTION_KEY = 'sviat__personal_data__retention_years';
    private const string POLICY_PAGE_KEY = 'sviat__personal_data__policy_page_id';
    private const int RETENTION_DEFAULT = 3;
    private const int RETENTION_MIN = 1;
    private const int RETENTION_MAX = 10;

    public function __construct(private Settings $settings) {}

    public function isEnabled(string $key): bool
    {
        return (bool) $this->settings->get(self::PREFIX . $key);
    }

    public function enable(string $key): void
    {
        $this->settings->set(self::PREFIX . $key, 1);
    }

    public function disable(string $key): void
    {
        $this->settings->set(self::PREFIX . $key, 0);
    }

    public function setRetentionYears(int $years): void
    {
        $this->settings->set(self::RETENTION_KEY, max(self::RETENTION_MIN, min(self::RETENTION_MAX, $years)));
    }

    public function retentionYears(): int
    {
        $years = filter_var($this->settings->get(self::RETENTION_KEY), FILTER_VALIDATE_INT);
        if ($years === false) {
            return self::RETENTION_DEFAULT;
        }

        return max(self::RETENTION_MIN, min(self::RETENTION_MAX, $years));
    }

    /** @return int|null id сторінки політики; null — не вибрано */
    public function policyPageId(): ?int
    {
        $id = filter_var($this->settings->get(self::POLICY_PAGE_KEY), FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @param int|null $pageId null чи 0 — не вибрано */
    public function setPolicyPageId(?int $pageId): void
    {
        $this->settings->set(self::POLICY_PAGE_KEY, $pageId !== null && $pageId > 0 ? $pageId : 0);
    }
}

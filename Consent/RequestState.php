<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

/** Форми, відправку яких у цьому запиті відхилено через відсутню згоду. Між запитами не живе. */
final class RequestState
{
    /** @var array<string, true> */
    private array $refused = [];

    public function refuse(string $form): void
    {
        $this->refused[$form] = true;
    }

    public function isRefused(string $form): bool
    {
        return isset($this->refused[$form]);
    }
}

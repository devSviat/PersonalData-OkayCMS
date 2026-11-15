<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

use Okay\Core\Languages;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\RecordProbe;
use Okay\Modules\Sviat\PersonalData\Policy\PolicyPage;
use Psr\Log\LoggerInterface;

/**
 * Квитки згоди цього запиту: по одному на форму, кожен забирається один раз. Між запитами не живуть,
 * тож згода з іншої сесії, форми чи попередньої відправки не прив'язується.
 */
final class ConsentLedger implements ConsentSink
{
    /** @var array<string, ConsentTicket> */
    private array $tickets = [];

    public function __construct(
        private readonly FormRegistry $forms,
        private readonly RecordProbe $probe,
        private readonly RenderProof $proof,
        private readonly Languages $languages,
        private readonly PolicyPage $policyPage,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Мова й сторінка політики — з доказу показу. Без доказу — мова сторінки й сторінка, яку мітка показала б
     * за поточних налаштувань. Клієнт їх не надсилає. Збій не зупиняє відправку форми: квитка тоді немає, а в лозі попередження.
     */
    #[\Override]
    public function issue(string $form): void
    {
        try {
            $descriptor = $this->forms->get($form);
            if ($descriptor === null) {
                $this->logger->warning("Sviat/PersonalData: consent ticket not issued, unknown form \"$form\"");

                return;
            }

            $shown = $this->proof->shown($form);
            $this->tickets[$form] = new ConsentTicket(
                $form,
                $shown['langId'] ?? (int) $this->languages->getLangId(),
                $shown === null ? $this->policyPage->linkedId() : $shown['policyPageId'],
                date('Y-m-d H:i:s'),
                $descriptor->recordHasIp ? null : self::clientIp(),
                $this->probe->maxId($descriptor),
            );
        } catch (\Throwable $e) {
            unset($this->tickets[$form]);
            $this->logger->warning("Sviat/PersonalData: consent ticket not issued for form \"$form\": " . $e::class);
        }
    }

    public function has(string $form): bool
    {
        return isset($this->tickets[$form]);
    }

    /** Квиток форми; після цього виклику його більше немає. */
    public function take(string $form): ?ConsentTicket
    {
        $ticket = $this->tickets[$form] ?? null;
        unset($this->tickets[$form]);

        return $ticket;
    }

    /** Колонка IP має 40 символів, як у ядрі; стислий IPv6 не довший за 39. */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && strlen($ip) <= 40 ? $ip : null;
    }
}

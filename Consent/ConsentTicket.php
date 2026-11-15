<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

/** Згоду на форму дано в цьому запиті; рядок з'явиться, лише коли шов прив'яже її до створеного запису. */
final readonly class ConsentTicket
{
    /**
     * @param int $langId мова показу чекбокса (з доказу показу), інакше мова сторінки
     * @param int|null $policyPageId сторінка політики в посиланні мітки (з доказу показу); null — посилання не було
     * @param string|null $ip лише для форм, запис яких не має надійного IP
     * @param int $maxRecordId найбільший id запису форми на момент згоди: новий запис має бути більшим
     */
    public function __construct(
        public string $form,
        public int $langId,
        public ?int $policyPageId,
        public string $givenAt,
        public ?string $ip,
        public int $maxRecordId,
    ) {}
}

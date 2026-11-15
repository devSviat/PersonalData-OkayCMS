<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

/** Куди шлюз передає дану згоду: запис з'являється, коли шов прив'язки знає id запису форми. */
interface ConsentSink
{
    /** Згоду на форму дано в цьому запиті. */
    public function issue(string $form): void;
}

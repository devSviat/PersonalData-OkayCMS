<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Core\BackendPostRedirectGet;

/** PRG без сесії й виходу: записує повідомлення й редіректи. Справжній деструктор читає запит, якого в тесті немає. */
final class PrgSpy extends BackendPostRedirectGet
{
    /** @var list<string> */
    public array $success = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $redirects = [];

    public function __construct() {}

    public function __destruct() {}

    public function storeMessageSuccess($message)
    {
        $this->success[] = (string) $message;
    }

    public function storeMessageError($message)
    {
        $this->errors[] = (string) $message;
    }

    public function redirect($urlRedirectToList = '')
    {
        $this->redirects[] = (string) $urlRedirectToList;
    }
}

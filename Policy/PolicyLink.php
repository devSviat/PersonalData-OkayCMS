<?php

namespace Okay\Modules\Sviat\PersonalData\Policy;

use Okay\Core\Router;

/**
 * Адреса сторінки політики конфіденційності для посилання в мітці згоди мовою покупця.
 *
 * Сторінка шукається серед видимих сторінок, які ядро вже завантажило для шаблону
 * (MainHelper передає їх у дизайн як `pages` на кожному запиті вітрини), тож видимість завжди
 * свіжа, а запитів немає. Адресу будує Router::generateUrl('page'), як `{url_generator route='page'}`,
 * тож мовний префікс поточної мови ставить ядро.
 */
final class PolicyLink
{
    /**
     * @param int|null $pageId сторінка з налаштувань модуля
     * @param iterable<mixed>|null $visiblePages сторінки з полями `id` і `url`; null, якщо дизайн їх не має
     * @return string|null null — сторінку не вибрано, приховано чи адреса непридатна: мітка без посилання
     */
    public static function href(?int $pageId, ?iterable $visiblePages): ?string
    {
        if ($pageId === null) {
            return null;
        }
        foreach ($visiblePages ?? [] as $page) {
            if (!is_object($page) || (int) ($page->id ?? 0) !== $pageId) {
                continue;
            }
            $slug = (string) ($page->url ?? '');
            if ($slug === '') {
                return null;
            }
            // Збій генерації адреси не має зривати сторінку з формою: тоді мітка просто без посилання.
            try {
                $href = Router::generateUrl('page', ['url' => $slug]);
            } catch (\Throwable) {
                return null;
            }

            // Слаг — дані з бази; адреса з нього проходить білий список, щоб не стати посиланням на інший хост.
            return is_string($href) && PolicyUrl::validate($href) ? $href : null;
        }

        return null;
    }
}

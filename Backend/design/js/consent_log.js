/* Фаза перехоплення: обидва обробники мають спрацювати раніше за обробники ядра на самих елементах. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        if (!event.target.closest) {
            return;
        }
        /* Вкладки модуля — окремі сторінки. Обробник ядра на .tab_navigation_link перемикає вкладки
           всередині сторінки й скасовує перехід, тож до нього клік не доходить. */
        if (event.target.closest('.sviat-pd-tabs a')) {
            event.stopPropagation();
            return;
        }
        /* Кнопка дії в журналі передає діалогу підтвердження рядок, з яким він працює. */
        var trigger = event.target.closest('[data-sviat-pd-id]');
        var dialog = trigger ? document.querySelector(trigger.getAttribute('data-target')) : null;
        if (!dialog) {
            return;
        }
        dialog.querySelector('input[name="id"]').value = trigger.getAttribute('data-sviat-pd-id');
        dialog.querySelector('[data-sviat-pd-subject]').textContent = trigger.getAttribute('data-sviat-pd-subject');
    }, true);
}());

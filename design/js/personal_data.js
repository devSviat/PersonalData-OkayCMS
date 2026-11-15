(function (d) {
    var refused = d.querySelector('.sviat-pd__input[aria-invalid=true]');
    if (!refused) {
        return;
    }
    // autofocus не діє на полі, захованому до показу форми (модальне вікно, вкладка), тож фокус ставиться, щойно поле стає видимим.
    var pending = 0;
    // Не частіше разу на кадр: слайдери змінюють style щокадру, а getClientRects() рахує розкладку.
    var observer = new MutationObserver(function () {
        pending = pending || requestAnimationFrame(focus);
    });
    function focus() {
        pending = 0;
        if (refused.getClientRects().length) {
            observer.disconnect();
            refused.focus();
        }
    }
    observer.observe(d.body, {attributes: true, subtree: true, attributeFilter: ['class', 'style', 'hidden', 'open']});
    focus();
})(document);

<form method="post">
    <button type="submit" name="checkout" class="hidden" aria-hidden="true" tabindex="-1"></button>
    <div class="form__body">
        <input class="form__input" name="name" type="text" value="{$request_data.name|escape}">
    </div>
    <div class="form__footer">
        <button class="form__button" type="submit" name="checkout">{$lang->cart_checkout}</button>
    </div>
</form>

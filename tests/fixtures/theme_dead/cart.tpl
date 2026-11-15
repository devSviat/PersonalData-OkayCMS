<form method="post" class="fn_validate_cart">
    {form_token name="checkout"}
    <div class="form__body">
        <textarea class="form__textarea" name="comment" aria-label="{$lang->cart_order_comment|escape}">{$request_data.comment|escape}</textarea>
    </div>
    <div class="form__footer">
        <input type="hidden" name="checkout" value="1">
        <a class="form__button" href="{url_generator route='cart'}">{$lang->cart_checkout}</a>
    </div>
</form>

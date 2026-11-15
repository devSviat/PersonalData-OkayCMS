<form method="post" class="fn_validate_cart">
    {form_token name="checkout"}
    <div class="form__body">
        <textarea class="form__textarea" name="comment" aria-label="{$lang->cart_order_comment|escape}">{$request_data.comment|escape}</textarea>
    </div>
    <div class="form__footer">
        <input type="hidden" name="checkout" value="1">
        <button class="form__button" type="submit" name="checkout" {if $settings->captcha_type == "invisible"}data-badge='bottomleft'{/if} value="{$lang->cart_checkout}">
            <span>{$lang->cart_checkout}</span>
        </button>
    </div>
</form>

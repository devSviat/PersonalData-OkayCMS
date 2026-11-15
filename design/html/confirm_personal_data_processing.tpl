{* Квадратик — розмітка чекбокса тем OkayCMS, його малює тема; клас .error дає колір рамки при відмові. Посилання поза <label>, щоб не перемикати чекбокс;
   без видимої сторінки політики весь підпис лишається в <label> простим текстом. label.error за aria-describedby jQuery Validate
   заповнює сама, а не вставляє нову мітку між полем і квадратиком. *}
<div class="sviat-pd">
    <label class="checkbox sviat-pd__label" for="{$sviat_pd.id|escape}">
        <input class="checkbox__input sviat-pd__input" id="{$sviat_pd.id|escape}" type="checkbox" name="sviat__personal_data__confirm" value="1" required data-msg-required="{$sviat_pd.error|escape}" aria-labelledby="{$sviat_pd.id|escape}-label{if $sviat_pd.policy_href} {$sviat_pd.id|escape}-more{/if}" aria-describedby="{$sviat_pd.id|escape}-error"{if $sviat_pd.refused} aria-invalid="true"{/if}>
        <svg class="checkbox__icon error" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
            <path class="checkbox__mark" fill="none" d="M4 10 l5 4 8-8.5"></path>
        </svg>
        <span class="sviat-pd__before" id="{$sviat_pd.id|escape}-label">{$sviat_pd.agree|escape}{if !$sviat_pd.policy_href} {$sviat_pd.terms|escape}{/if}</span>
    </label>
    {if $sviat_pd.policy_href}
        <a class="sviat-pd__more" id="{$sviat_pd.id|escape}-more" href="{$sviat_pd.policy_href|escape}" target="_blank" rel="noopener">{$sviat_pd.terms|escape}</a>
    {/if}
    <label class="sviat-pd__error error" id="{$sviat_pd.id|escape}-error">{if $sviat_pd.refused}{$sviat_pd.error|escape}{/if}</label>
</div>

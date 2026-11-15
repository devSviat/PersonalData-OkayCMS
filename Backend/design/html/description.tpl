{$meta_title = $btr->sviat__personal_data__title|escape scope=global}

<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="heading_page">{$btr->sviat__personal_data__title|escape}</div>
    </div>
</div>

{include file='tabs.tpl'}

{if $sviat_pd_needs_update}
    {include file='migration_banner.tpl'}
{else}
    {if $message_success}
        <div class="alert alert--icon alert--success" role="status">
            <div class="alert__content">
                <div class="alert__title">{$btr->sviat__personal_data__settings_saved|escape}</div>
            </div>
        </div>
    {elseif $message_error}
        <div class="alert alert--icon alert--error" role="alert">
            <div class="alert__content">
                <div class="alert__title">{$btr->sviat__personal_data__session_error|escape}</div>
            </div>
        </div>
    {/if}

    <form method="post" class="sviat-pd-page">
        {* Без цього поля ядро відхиляє POST з кодом 403: CSRF-перевірка стоїть до виклику контролера. *}
        <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">

        <div class="sviat-pd-groups">
            {foreach $sviat_pd_groups as $group => $rows}
                <section class="boxed mb-2 sviat-pd-group" aria-labelledby="sviat-pd-group-{$group|escape}">
                    <h2 class="heading_box mb-1" id="sviat-pd-group-{$group|escape}">{$btr->getTranslation("sviat__personal_data__group_`$group`")|escape}</h2>
                    {foreach $rows as $row}
                        <div class="sviat-pd-form{if !$row.available} sviat-pd-form--off{/if}">
                            <div class="sviat-pd-form__text">
                                <label class="sviat-pd-form__title" for="sviat-pd-form-{$row.key|escape}">{$row.title|escape}</label>
                                <div class="sviat-pd-form__meta">{$btr->sviat__personal_data__collects|escape} {$row.collects|escape}</div>
                                <div class="sviat-pd-form__meta">
                                    {if !$row.available}
                                        {$btr->sviat__personal_data__requires|escape} <code>{$row.requires|escape}</code>
                                    {elseif $row.lastConsentAt}
                                        {$btr->sviat__personal_data__last_consent|escape} <time datetime="{$row.lastConsentAt|escape}">{$row.lastConsentAt|date|escape} {$row.lastConsentAt|time|escape}</time>
                                    {else}
                                        {$btr->sviat__personal_data__no_consents|escape}
                                    {/if}
                                </div>
                            </div>
                            <label class="switch switch-default">
                                <input class="switch-input" id="sviat-pd-form-{$row.key|escape}" name="forms[{$row.key|escape}]" value="1" type="checkbox"{if $row.enabled} checked{/if}{if !$row.available} disabled{/if}>
                                <span class="switch-label"></span>
                                <span class="switch-handle"></span>
                            </label>
                        </div>
                    {/foreach}
                </section>
            {/foreach}
        </div>

        <div class="row">
            <div class="col-lg-6 col-md-12">
                <section class="boxed mb-2" aria-labelledby="sviat-pd-settings">
                    <h2 class="heading_box mb-1" id="sviat-pd-settings">{$btr->sviat__personal_data__settings_title|escape}</h2>
                    <div class="sviat-pd-field">
                        <label class="heading_label" for="sviat-pd-policy-page">{$btr->sviat__personal_data__policy_page|escape}</label>
                        <select class="selectpicker form-control" id="sviat-pd-policy-page" name="policy_page_id" data-live-search="{if count($sviat_pd_pages) > 10}true{else}false{/if}" aria-describedby="sviat-pd-policy-page-hint">
                            <option value="0">{$btr->sviat__personal_data__policy_page_none|escape}</option>
                            {foreach $sviat_pd_pages as $p}
                                <option value="{$p.id|escape}"{if $p.id == $sviat_pd_policy_page_id} selected{/if}>{$p.name|escape}{if !$p.visible} ({$btr->sviat__personal_data__policy_page_hidden|escape}){/if}</option>
                            {/foreach}
                        </select>
                        <p class="sviat-pd-hint" id="sviat-pd-policy-page-hint">{$btr->sviat__personal_data__policy_page_hint|escape}</p>
                    </div>
                    <label class="heading_label" for="sviat-pd-retention-years">{$btr->sviat__personal_data__retention_title|escape}</label>
                    <div class="sviat-pd-retention">
                        <span>{$btr->sviat__personal_data__retention_label|escape}</span>
                        <input class="form-control sviat-pd-retention__input" id="sviat-pd-retention-years" name="retention_years" type="number" min="1" max="10" step="1" value="{$sviat_pd_retention|escape}" aria-describedby="sviat-pd-retention-hint">
                        <span>{$btr->sviat__personal_data__retention_unit|escape}</span>
                    </div>
                    <p class="sviat-pd-hint mb-0" id="sviat-pd-retention-hint">{$btr->sviat__personal_data__retention_hint|escape}</p>
                </section>
            </div>
            <div class="col-lg-6 col-md-12">
                <section class="boxed mb-2" aria-labelledby="sviat-pd-notes">
                    <h2 class="heading_box mb-1" id="sviat-pd-notes">{$btr->sviat__personal_data__notes_title|escape}</h2>
                    <ul class="sviat-pd-notes">
                        <li>{$btr->sviat__personal_data__note_subscribe|escape}</li>
                        <li>{$btr->sviat__personal_data__note_cart_parts|escape}</li>
                        <li>{$btr->sviat__personal_data__note_shortcode|escape}</li>
                    </ul>
                </section>
            </div>
        </div>

        <div class="sviat-pd-actions">
            <button type="submit" class="btn btn_small btn_blue">
                {include file='svg_icon.tpl' svgId='checked'}
                <span>{$btr->general_apply|escape}</span>
            </button>
        </div>
    </form>
{/if}

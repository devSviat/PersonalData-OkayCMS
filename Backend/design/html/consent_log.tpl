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
                <div class="alert__title">{$btr->getTranslation("sviat__personal_data__flash_`$message_success`")|escape}</div>
            </div>
        </div>
    {elseif $message_error}
        <div class="alert alert--icon alert--error" role="alert">
            <div class="alert__content">
                <div class="alert__title">{$btr->getTranslation("sviat__personal_data__flash_`$message_error`")|escape}</div>
            </div>
        </div>
    {/if}

    <section class="boxed mb-2" aria-labelledby="sviat-pd-log-filters">
        <h2 class="heading_box mb-1" id="sviat-pd-log-filters">{$btr->sviat__personal_data__log_filters|escape}</h2>
        <form method="get" class="sviat-pd-filters">
            <input type="hidden" name="controller" value="Sviat.PersonalData.ConsentLogAdmin">
            <div class="sviat-pd-filters__field">
                <label class="heading_label" for="sviat-pd-f-form">{$btr->sviat__personal_data__log_form|escape}</label>
                <select class="form-control" id="sviat-pd-f-form" name="form">
                    <option value="">{$btr->sviat__personal_data__log_any|escape}</option>
                    {foreach $sviat_pd_forms as $key => $title}
                        <option value="{$key|escape}"{if $key == $sviat_pd_values.form} selected{/if}>{$title|escape}</option>
                    {/foreach}
                </select>
            </div>
            <div class="sviat-pd-filters__field">
                <label class="heading_label" for="sviat-pd-f-from">{$btr->sviat__personal_data__log_from|escape}</label>
                <input class="form-control" id="sviat-pd-f-from" name="from" type="date" value="{$sviat_pd_values.from|escape}">
            </div>
            <div class="sviat-pd-filters__field">
                <label class="heading_label" for="sviat-pd-f-to">{$btr->sviat__personal_data__log_to|escape}</label>
                <input class="form-control" id="sviat-pd-f-to" name="to" type="date" value="{$sviat_pd_values.to|escape}">
            </div>
            <div class="sviat-pd-filters__field">
                <label class="heading_label" for="sviat-pd-f-lang">{$btr->sviat__personal_data__log_lang|escape}</label>
                <select class="form-control" id="sviat-pd-f-lang" name="lang_id" aria-describedby="sviat-pd-period-hint">
                    <option value="">{$btr->sviat__personal_data__log_any|escape}</option>
                    {foreach $sviat_pd_languages as $id => $name}
                        <option value="{$id|escape}"{if $id == $sviat_pd_values.lang_id} selected{/if}>{$name|escape}</option>
                    {/foreach}
                </select>
            </div>
            <div class="sviat-pd-filters__field">
                <label class="heading_label" for="sviat-pd-f-state">{$btr->sviat__personal_data__log_state|escape}</label>
                <select class="form-control" id="sviat-pd-f-state" name="state" aria-describedby="sviat-pd-period-hint">
                    <option value="">{$btr->sviat__personal_data__log_any|escape}</option>
                    {foreach ['active', 'withdrawn', 'anonymized'] as $state}
                        <option value="{$state|escape}"{if $state == $sviat_pd_values.state} selected{/if}>{$btr->getTranslation("sviat__personal_data__state_`$state`")|escape}</option>
                    {/foreach}
                </select>
            </div>
            <div class="sviat-pd-filters__field">
                <label class="heading_label" for="sviat-pd-f-record">{$btr->sviat__personal_data__log_record|escape}</label>
                <input class="form-control" id="sviat-pd-f-record" name="record" type="text" inputmode="numeric" pattern="[0-9]*" value="{$sviat_pd_values.record|escape}">
            </div>
            <div class="sviat-pd-filters__actions">
                <button type="submit" class="btn btn_small btn_blue">{$btr->sviat__personal_data__log_apply|escape}</button>
                <a class="btn btn_small btn_border-info" href="{url controller='Sviat.PersonalData.ConsentLogAdmin' form=null from=null to=null lang_id=null state=null record=null page=null}">{$btr->sviat__personal_data__log_reset|escape}</a>
            </div>
            <p class="sviat-pd-hint sviat-pd-filters__hint" id="sviat-pd-period-hint">{$btr->sviat__personal_data__log_period_hint|escape}</p>
        </form>
    </section>

    <div class="sviat-pd-log-bar">
        <p class="sviat-pd-log-bar__count" role="status">{$btr->sviat__personal_data__log_found|escape} <strong>{$sviat_pd_total|escape}</strong></p>
        {if $sviat_pd_total > 0}
            <form method="post" class="sviat-pd-log-bar__export">
                <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                <input type="hidden" name="action" value="export">
                <button type="submit" class="btn btn_small btn_border-info" aria-describedby="sviat-pd-export-hint">{$btr->sviat__personal_data__log_export|escape}</button>
                <span class="sviat-pd-hint" id="sviat-pd-export-hint">{$btr->sviat__personal_data__log_export_hint|escape}</span>
            </form>
        {/if}
    </div>

    {if $sviat_pd_rows}
        <div class="okay_list sviat-pd-log">
            <div class="okay_list_head" aria-hidden="true">
                <div class="okay_list_heading sviat-pd-log__given">{$btr->sviat__personal_data__log_col_given|escape}</div>
                <div class="okay_list_heading sviat-pd-log__record">{$btr->sviat__personal_data__log_col_record|escape}</div>
                <div class="okay_list_heading sviat-pd-log__policy">{$btr->sviat__personal_data__log_col_policy|escape}</div>
                <div class="okay_list_heading sviat-pd-log__lang">{$btr->sviat__personal_data__log_col_lang|escape}</div>
                <div class="okay_list_heading sviat-pd-log__ip">{$btr->sviat__personal_data__log_col_ip|escape}</div>
                <div class="okay_list_heading sviat-pd-log__state">{$btr->sviat__personal_data__log_col_state|escape}</div>
                <div class="okay_list_heading sviat-pd-log__actions">{$btr->sviat__personal_data__log_col_actions|escape}</div>
            </div>
            <ul class="okay_list_body sviat-pd-log__body">
                {foreach $sviat_pd_rows as $row}
                    {$subject = "`$row.formTitle` #`$row.recordId`"}
                    <li class="okay_list_body_item sviat-pd-log__item" id="sviat-pd-row-{$row.id|escape}">
                        <div class="okay_list_row">
                            <div class="okay_list_boding sviat-pd-log__given" data-label="{$btr->sviat__personal_data__log_col_given|escape}">
                                <time datetime="{$row.givenAt|escape}">{$row.givenAt|date|escape} {$row.givenAt|time|escape}</time>
                            </div>
                            <div class="okay_list_boding sviat-pd-log__record" data-label="{$btr->sviat__personal_data__log_col_record|escape}">
                                <span class="sviat-pd-log__form">{$row.formTitle|escape}</span>
                                {if $row.recordId}
                                    {if $row.recordUrl}
                                        <a href="{$row.recordUrl|escape}">#{$row.recordId|escape}</a>
                                    {else}
                                        <span>#{$row.recordId|escape}</span>
                                    {/if}
                                {/if}
                            </div>
                            <div class="okay_list_boding sviat-pd-log__policy" data-label="{$btr->sviat__personal_data__log_col_policy|escape}">
                                {if $row.policyPageName !== null}
                                    <a href="{$row.policyPageUrl|escape}">{$row.policyPageName|escape}</a>
                                {elseif $row.policyPageId}
                                    <span class="text_grey">{$btr->sviat__personal_data__log_policy_gone|escape} #{$row.policyPageId|escape}</span>
                                {else}
                                    <span class="text_grey">{$btr->sviat__personal_data__log_policy_none|escape}</span>
                                {/if}
                            </div>
                            <div class="okay_list_boding sviat-pd-log__lang" data-label="{$btr->sviat__personal_data__log_col_lang|escape}">{$row.lang|escape}</div>
                            <div class="okay_list_boding sviat-pd-log__ip" data-label="{$btr->sviat__personal_data__log_col_ip|escape}">
                                {if $row.ip}<code>{$row.ip|escape}</code>{else}<span class="text_grey">—</span>{/if}
                            </div>
                            <div class="okay_list_boding sviat-pd-log__state" data-label="{$btr->sviat__personal_data__log_col_state|escape}">
                                <span class="sviat-pd-chip sviat-pd-chip--{$row.state|escape}">{$btr->getTranslation("sviat__personal_data__state_`$row.state`")|escape}</span>
                                {if $row.state == 'withdrawn'}
                                    <span class="sviat-pd-log__who">{$row.withdrawnBy|escape}, <time datetime="{$row.withdrawnAt|escape}">{$row.withdrawnAt|date|escape} {$row.withdrawnAt|time|escape}</time></span>
                                {elseif $row.state == 'anonymized'}
                                    <span class="sviat-pd-log__who"><time datetime="{$row.anonymizedAt|escape}">{$row.anonymizedAt|date|escape} {$row.anonymizedAt|time|escape}</time></span>
                                {/if}
                            </div>
                            <div class="okay_list_boding sviat-pd-log__actions">
                                {if $row.state == 'active'}
                                    <button type="button" class="btn btn_small btn_border-info" data-toggle="modal" data-target="#sviat-pd-confirm-withdraw" data-sviat-pd-id="{$row.id|escape}" data-sviat-pd-subject="{$subject|escape}">{$btr->sviat__personal_data__action_withdraw|escape}</button>
                                {/if}
                                {if $row.state != 'anonymized'}
                                    <button type="button" class="btn btn_small btn-outline-danger" data-toggle="modal" data-target="#sviat-pd-confirm-anonymize" data-sviat-pd-id="{$row.id|escape}" data-sviat-pd-subject="{$subject|escape}">{$btr->sviat__personal_data__action_anonymize|escape}</button>
                                {/if}
                            </div>
                        </div>
                    </li>
                {/foreach}
            </ul>
        </div>
        {include file='pagination.tpl'}
    {else}
        <div class="boxed sviat-pd-empty">
            {if $sviat_pd_filtered}
                {$btr->sviat__personal_data__log_empty_filtered|escape}
            {else}
                {$btr->sviat__personal_data__log_empty|escape}
            {/if}
        </div>
    {/if}

    {foreach ['withdraw', 'anonymize'] as $action}
        <div id="sviat-pd-confirm-{$action|escape}" class="modal fade sviat-pd-confirm" role="dialog" aria-labelledby="sviat-pd-confirm-{$action|escape}-title" aria-describedby="sviat-pd-confirm-{$action|escape}-text" aria-hidden="true">
            <div class="modal-dialog modal-md">
                <div class="modal-content">
                    <div class="card-header">
                        <h2 class="heading_modal" id="sviat-pd-confirm-{$action|escape}-title">{$btr->getTranslation("sviat__personal_data__confirm_`$action`_title")|escape}</h2>
                    </div>
                    <form method="post" class="modal-body">
                        <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                        <input type="hidden" name="action" value="{$action|escape}">
                        <input type="hidden" name="id" value="">
                        <p class="sviat-pd-confirm__subject" data-sviat-pd-subject></p>
                        <p class="sviat-pd-confirm__text" id="sviat-pd-confirm-{$action|escape}-text">{$btr->getTranslation("sviat__personal_data__confirm_`$action`_text")|escape}</p>
                        <div class="sviat-pd-confirm__buttons">
                            <button type="submit" class="btn btn_small {if $action == 'anonymize'}btn-danger{else}btn_blue{/if}">{$btr->getTranslation("sviat__personal_data__confirm_`$action`_yes")|escape}</button>
                            <button type="button" class="btn btn_small btn_border-info" data-dismiss="modal" autofocus>{$btr->sviat__personal_data__confirm_cancel|escape}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    {/foreach}
{/if}

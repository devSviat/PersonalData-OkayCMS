{$meta_title = $btr->sviat__personal_data__title|escape scope=global}

{*Назва сторінки*}
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="heading_page">{$btr->sviat__personal_data__title|escape}</div>
    </div>
</div>

{if $message_success}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--success">
                <div class="alert__content">
                    <div class="alert__title">{$btr->sviat__personal_data__settings_saved|escape}</div>
                </div>
            </div>
        </div>
    </div>
{/if}

<div class="row">
    <div class="col-lg-12 col-md-12 col-sm-12">
        <div class="alert alert--icon alert--info">
            <div class="alert__content">
                <div class="alert__title">{$btr->sviat__personal_data__info|escape}</div>
                <p>{$btr->sviat__personal_data__description_2}</p>
                <p class="mb-0"><strong>{$btr->sviat__personal_data__warning|escape}</strong> {$btr->sviat__personal_data__description_1}</p>
            </div>
        </div>
    </div>
</div>

{*Головна форма сторінки*}
<form method="post" enctype="multipart/form-data">
    {* Без цього поля ядро відхиляє POST з кодом 403: CSRF-перевірка стоїть до
       виклику контролера і стосується всіх сторінок, крім входу. *}
    <input type="hidden" name="session_id" value="{$smarty.session.id}">

    <div class="row">
        <div class="col-lg-12 col-md-12">
            <div class="boxed">
                <div class="heading_label">{$btr->sviat__personal_data__where_to_show|escape}</div>

                <div class="permission_block">
                    <div class="permission_boxes row">
                        <div class="col-xl-4 col-lg-4 col-md-6">
                            <div class="permission_box permission_box--long">
                                <span>{$btr->settings_general_cart|escape}</span>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="pd_cart" value="1" type="checkbox"
                                           {if $settings->pd_cart}checked=""{/if} />
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-4 col-md-6">
                            <div class="permission_box permission_box--long">
                                <span>{$btr->settings_general_register|escape}</span>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="pd_register" value="1" type="checkbox"
                                           {if $settings->pd_register}checked=""{/if} />
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-4 col-md-6">
                            <div class="permission_box permission_box--long">
                                <span>{$btr->settings_general_contact_form|escape}</span>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="pd_feedback" value="1" type="checkbox"
                                           {if $settings->pd_feedback}checked=""{/if} />
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-4 col-md-6">
                            <div class="permission_box permission_box--long">
                                <span>{$btr->settings_general_callback|escape}</span>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="pd_callback" value="1" type="checkbox"
                                           {if $settings->pd_callback}checked=""{/if} />
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-4 col-md-6">
                            <div class="permission_box permission_box--long">
                                <span>{$btr->sviat__personal_data__comment_product|escape}</span>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="pd_comment_product" value="1" type="checkbox"
                                           {if $settings->pd_comment_product}checked=""{/if} />
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-4 col-md-6">
                            <div class="permission_box permission_box--long">
                                <span>{$btr->settings_general_comment|escape}</span>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="pd_comment" value="1" type="checkbox"
                                           {if $settings->pd_comment}checked=""{/if} />
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-12 col-md-12">
                        <button type="submit" class="btn btn_small btn_blue float-md-right">
                            {include file='svg_icon.tpl' svgId='checked'}
                            <span>{$btr->general_apply|escape}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

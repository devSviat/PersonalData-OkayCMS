<?php

$lang['sviat__personal_data__title'] = 'Permission to process personal data';
$lang['sviat__personal_data__settings_saved'] = 'Settings saved';
$lang['sviat__personal_data__schema_banner'] = 'Press "Update" for the module in the module list.';

$lang['sviat__personal_data__group_orders'] = 'Orders';
$lang['sviat__personal_data__group_account'] = 'Account';
$lang['sviat__personal_data__group_requests'] = 'Requests';
$lang['sviat__personal_data__group_reviews'] = 'Reviews and comments';

$lang['sviat__personal_data__form_title_cart'] = 'Checkout in the cart';
$lang['sviat__personal_data__form_title_register'] = 'Registration';
$lang['sviat__personal_data__form_title_feedback'] = 'Contact form';
$lang['sviat__personal_data__form_title_callback'] = 'Callback request';
$lang['sviat__personal_data__form_title_comment_product'] = 'Product review';
$lang['sviat__personal_data__form_title_comment_post'] = 'Blog comment';
$lang['sviat__personal_data__form_title_fast_order'] = 'One-click order';
$lang['sviat__personal_data__form_title_back_in_stock'] = 'Back-in-stock alert';
$lang['sviat__personal_data__form_title_parts_selection'] = 'Parts selection';
$lang['sviat__personal_data__form_title_order_return'] = 'Return request';
$lang['sviat__personal_data__form_title_review_request'] = 'Invited review';

$lang['sviat__personal_data__field_name'] = 'name';
$lang['sviat__personal_data__field_phone'] = 'phone';
$lang['sviat__personal_data__field_email'] = 'email';
$lang['sviat__personal_data__field_address'] = 'address';
$lang['sviat__personal_data__field_comment'] = 'comment';
$lang['sviat__personal_data__field_password'] = 'password';
$lang['sviat__personal_data__field_text'] = 'text';
$lang['sviat__personal_data__field_rating'] = 'rating';
$lang['sviat__personal_data__field_device'] = 'device model';
$lang['sviat__personal_data__field_photo'] = 'photo';
$lang['sviat__personal_data__field_tax_id'] = 'tax ID';
$lang['sviat__personal_data__field_iban'] = 'IBAN';
$lang['sviat__personal_data__field_order'] = 'order number';

$lang['sviat__personal_data__collects'] = 'Collects:';
$lang['sviat__personal_data__last_consent'] = 'Last consent:';
$lang['sviat__personal_data__no_consents'] = 'No consents yet';
$lang['sviat__personal_data__requires'] = 'Unavailable: requires module';

$lang['sviat__personal_data__settings_title'] = 'Settings';
$lang['sviat__personal_data__policy_page'] = 'Privacy policy page';
$lang['sviat__personal_data__policy_page_none'] = '— not selected —';
$lang['sviat__personal_data__policy_page_hidden'] = 'hidden';
$lang['sviat__personal_data__policy_page_hint'] = 'The end of the checkbox label links to it, in the customer\'s language, in a new tab. Not selected or hidden: the label has no link.';
$lang['sviat__personal_data__retention_title'] = 'Retention';
$lang['sviat__personal_data__retention_label'] = 'Delete anonymised consents after';
$lang['sviat__personal_data__retention_unit'] = 'years';
$lang['sviat__personal_data__retention_hint'] = 'A consent is anonymised when its record (order, user or request) is deleted. From 1 to 10 years.';

$lang['sviat__personal_data__notes_title'] = 'Notes';
$lang['sviat__personal_data__note_subscribe'] = 'The newsletter form collects only an email and asks for no consent.';
$lang['sviat__personal_data__note_cart_parts'] = 'Delivery and payment fields added by modules are sent with the cart form, so the cart consent covers them. Mention delivery and data transfer to the carrier in the privacy policy.';
$lang['sviat__personal_data__note_shortcode'] = 'The {confirm_personal_data_processing} checkbox works only for the forms in this list.';
$lang['sviat__personal_data__session_error'] = 'The session has expired. Reload the page and try again.';

$lang['sviat__personal_data__tab_forms'] = 'Forms';
$lang['sviat__personal_data__tab_log'] = 'Log';

$lang['sviat__personal_data__log_filters'] = 'Filters';
$lang['sviat__personal_data__log_form'] = 'Form';
$lang['sviat__personal_data__log_any'] = 'All';
$lang['sviat__personal_data__log_from'] = 'Period from';
$lang['sviat__personal_data__log_to'] = 'to';
$lang['sviat__personal_data__log_lang'] = 'Language';
$lang['sviat__personal_data__log_state'] = 'State';
$lang['sviat__personal_data__log_record'] = 'Record number';
$lang['sviat__personal_data__log_apply'] = 'Show';
$lang['sviat__personal_data__log_reset'] = 'Reset';
$lang['sviat__personal_data__log_period_hint'] = 'Language and state apply only together with a period: fill in both dates.';
$lang['sviat__personal_data__log_found'] = 'Consents found:';
$lang['sviat__personal_data__log_export'] = 'Export CSV';
$lang['sviat__personal_data__log_export_hint'] = 'The file contains full IP addresses. Every export is written to the log.';
$lang['sviat__personal_data__log_col_given'] = 'Consent date';
$lang['sviat__personal_data__log_col_record'] = 'Form and record';
$lang['sviat__personal_data__log_col_policy'] = 'Policy page';
$lang['sviat__personal_data__log_col_lang'] = 'Language';
$lang['sviat__personal_data__log_col_ip'] = 'IP';
$lang['sviat__personal_data__log_col_state'] = 'State';
$lang['sviat__personal_data__log_col_actions'] = 'Actions';
$lang['sviat__personal_data__log_policy_gone'] = 'Page deleted';
$lang['sviat__personal_data__log_policy_none'] = 'Not selected';
$lang['sviat__personal_data__log_empty'] = 'No consents yet. They appear when customers submit forms with the checkbox.';
$lang['sviat__personal_data__log_empty_filtered'] = 'No consents match these filters.';

$lang['sviat__personal_data__state_active'] = 'Given';
$lang['sviat__personal_data__state_withdrawn'] = 'Withdrawn';
$lang['sviat__personal_data__state_anonymized'] = 'Anonymized';

$lang['sviat__personal_data__action_withdraw'] = 'Mark as withdrawn';
$lang['sviat__personal_data__action_anonymize'] = 'Anonymize';
$lang['sviat__personal_data__confirm_withdraw_title'] = 'Mark the consent as withdrawn?';
$lang['sviat__personal_data__confirm_withdraw_text'] = 'The consent will be marked as withdrawn in your name with the current time. The shop record (order, request or review) is not deleted or changed. The row stays in the log.';
$lang['sviat__personal_data__confirm_withdraw_yes'] = 'Yes, withdraw';
$lang['sviat__personal_data__confirm_anonymize_title'] = 'Anonymize the consent?';
$lang['sviat__personal_data__confirm_anonymize_text'] = 'This cannot be undone. The IP address is erased and the link to the shop record is removed: only the fact of consent remains, and it is deleted after the retention period. The shop record itself is not deleted.';
$lang['sviat__personal_data__confirm_anonymize_yes'] = 'Yes, anonymize';
$lang['sviat__personal_data__confirm_cancel'] = 'Cancel';

$lang['sviat__personal_data__flash_withdrawn'] = 'Consent marked as withdrawn';
$lang['sviat__personal_data__flash_anonymized'] = 'Consent anonymized';
$lang['sviat__personal_data__flash_refused'] = 'Nothing was done: the row is gone or its state has changed. Reload the page.';
$lang['sviat__personal_data__flash_session'] = 'The session has expired. Reload the page and try again.';

$lang['sviat__personal_data__badge_withdrawn'] = 'Consent withdrawn';
$lang['sviat__personal_data__badge_open'] = 'Open in the consent log';

$lang['sviat__personal_data__menu_forms'] = 'Consents: forms';
$lang['sviat__personal_data__menu_log'] = 'Consent log';

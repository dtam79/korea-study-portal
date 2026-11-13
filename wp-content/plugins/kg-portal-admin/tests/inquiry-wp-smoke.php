<?php
/** DDEV-only DB transaction integration test. Invoke with a WordPress admin --user. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(1);
function kgpa80_assert(bool $valid,string $label): void {
    if (!$valid) WP_CLI::error('FAIL: '.$label);
    WP_CLI::log('PASS: '.$label);
}
$host=(string)wp_parse_url(home_url(),PHP_URL_HOST);
if (!str_ends_with($host,'.ddev.site')) WP_CLI::error('Run only on a local *.ddev.site project.');
kgpa80_assert(current_user_can('manage_options') && current_user_can('kgp_manage_inquiries'),'Admin and inquiry permissions');
kgpa80_assert(get_option('kgpa_inquiry_schema_version')===KGP_Operator_Inquiry_Schema::VERSION,'Additive inquiry schema installed');
$routes=rest_get_server()->get_routes();
foreach(['/kgp-operator/v1/inquiries','/kgp-operator/v1/inquiry-settings','/kgpa/v1/offerings'] as $route) {
    kgpa80_assert(array_key_exists($route,$routes),'Registered REST: '.$route);
}
kgpa80_assert((bool)wp_next_scheduled('kgpa_inquiry_retention_cleanup'),'Daily retention job scheduled');
$settings=KGP_Operator_Inquiry_Settings::get();
if (!$settings['intake_enabled']) kgpa80_assert(!KGP_Operator_Inquiry_Settings::ready(),'Collection blocked until privacy policy approval');
$original=get_option(KGP_Operator_Inquiry_Settings::OPTION,false);
$mailFilter=static fn()=>false;
add_filter('pre_wp_mail',$mailFilter,10,1);
class KGP_Operator80Rollback extends RuntimeException {}
try {
    // Smoke writes an explicitly synthetic temporary policy; always restores the previous setting.
    update_option(KGP_Operator_Inquiry_Settings::OPTION,[
        'intake_enabled'=>true, 'policy_version'=>'QA-2026-v1', 'retention_days'=>7,
        'purpose_ko'=>'합성 테스트 문의 응답 확인 전용 목적', 'purpose_en'=>'Synthetic test inquiry handling only',
        'notice_ko'=>'합성 테스트 안내문입니다. 이 문장은 실제 개인정보 안내문이 아닙니다.',
        'notice_en'=>'This is a test notice and not a real privacy policy.',
        'deletion_contact'=>'privacy@example.test','notify_email'=>'operator@example.test',
        'max_submissions_per_hour'=>5,
    ],false);
    kgpa80_assert(KGP_Operator_Inquiry_Settings::ready(),'Temporary test policy enables collection');
    $token=wp_generate_password(38,false,false);
    KGP_DB::transaction(static function() use($token) {
        $data=['email'=>'test@example.test','inquiry_locale'=>'en',
            'message_body'=>'Synthetic local test message, not a real inquiry.','policy_version'=>'QA-2026-v1','consent'=>'yes'];
        $first=KGP_Operator_Inquiries::submit($data,$token);
        $second=KGP_Operator_Inquiries::submit($data,$token);
        kgpa80_assert(!$first['duplicate'] && $second['duplicate'],'Same token is idempotent');
        global $wpdb;
        $t=KGP_DB::table('inquiries');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE receipt_code=%s",$first['receipt_code']),ARRAY_A);
        kgpa80_assert((bool)$row && $row['email_delivery_status']==='failed','Mail failure does not roll back saved inquiry');
        kgpa80_assert($row['consent_policy_version']==='QA-2026-v1' && $row['delete_after']>$row['created_at'],'Consent version and deletion deadline retained');
        $id=(int)$row['id'];
        KGP_Operator_Inquiries::update($id,['handling_status'=>'in_progress','assigned_to'=>get_current_user_id()]);
        KGP_Operator_Inquiries::note($id,'Synthetic internal note');
        $detail=KGP_Operator_Inquiries::detail($id);
        kgpa80_assert($detail['inquiry']['handling_status']==='in_progress' && count($detail['events'])>=4,'Inquiry status and audit trail');
        $anon=get_current_user_id();wp_set_current_user(0);
        try {
            $resp=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/inquiries'));
            kgpa80_assert($resp->get_status()>=400,'Unauthenticated private inquiry listing denied');
        } finally {wp_set_current_user($anon);}
        kgpa80_assert(KGP_Operator_Inquiries::erase($id,'operator_request',get_current_user_id()),'Physical deletion');
        $receipt=$wpdb->get_var($wpdb->prepare("SELECT receipt_code FROM $t WHERE id=%d",$id));
        kgpa80_assert(!$receipt,'Erased inquiry is absent');
        $events=KGP_DB::table('inquiry_events');
        kgpa80_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE inquiry_id=%d",$id))===0,'All internal notes erased');
        $audit=KGP_Operator_Inquiry_Schema::table('deletion_audit');
        kgpa80_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $audit WHERE reason_code=%s",'operator_request'))>=1,'Deletion audit written without PII');
        throw new KGP_Operator80Rollback('rollback');
    });
} catch(KGP_Operator80Rollback $e) {
    // DB changes intentionally rolled back.
} catch(Throwable $e) {
    WP_CLI::warning('Database transaction rolled back because: '.$e->getMessage());
    throw $e;
} finally {
    remove_filter('pre_wp_mail',$mailFilter,10);
    if ($original===false) delete_option(KGP_Operator_Inquiry_Settings::OPTION);
    else update_option(KGP_Operator_Inquiry_Settings::OPTION,$original,false);
}
WP_CLI::success('A08/A09/P08/P09 smoke passed. Synthetic DB records were rolled back and original settings restored.');

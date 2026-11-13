<?php
/** Offline functional tests, requires PHP 8.3+ but not WordPress/DDEV. */
define('ABSPATH','/');
function get_option($key,$fallback=[]){return $fallback;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)?$v:false;}
function wp_salt($context=''){return 'synthetic-test-only-not-for-production';}
require __DIR__.'/../inc/inquiry/class-settings.php';
require __DIR__.'/../inc/inquiry/class-inquiries.php';
require __DIR__.'/../inc/inquiry/class-export.php';
function test($condition,$message){if(!$condition)throw new RuntimeException('FAIL: '.$message);}
$draft=KGP_Operator_Inquiry_Settings::get();
test($draft['intake_enabled']===false,'Default collection is disabled.');
test(!KGP_Operator_Inquiry_Settings::ready($draft),'No policy means no real inquiry collection.');
$draft['intake_enabled']=true;
test(!KGP_Operator_Inquiry_Settings::ready($draft),'Enable flag alone cannot bypass privacy policy.');
$draft=array_merge($draft,[
    'policy_version'=>'2026-11-v1','retention_days'=>30,
    'purpose_ko'=>'유학 관련 문의 응대 및 운영 확인 목적','purpose_en'=>'Contact and operator inquiry support',
    'notice_ko'=>'문의 수집을 위한 예시 안내문입니다. 실제 운영 사용 금지.',
    'notice_en'=>'Synthetic example notice only. Not approved for actual collection.',
    'deletion_contact'=>'privacy@example.test','notify_email'=>'operator@example.test',
]);
test(KGP_Operator_Inquiry_Settings::ready($draft),'Complete synthetic policy passes structural gate.');
$draft['retention_days']=0;
test(!KGP_Operator_Inquiry_Settings::ready($draft),'Nonpositive retention must block collection.');
$draft['retention_days']=30;$draft['notify_email']='broken-email';
test(!KGP_Operator_Inquiry_Settings::ready($draft),'Invalid notification address must block collection.');
$a=KGP_Operator_Inquiries::receipt_for_token(str_repeat('a',38));
$b=KGP_Operator_Inquiries::receipt_for_token(str_repeat('b',38));
test($a!==$b&&preg_match('/^KGP-[A-F0-9]{24}$/D',$a)===1,'Receipt is opaque and token-derived.');
foreach(['=1+1','+1-1','-2+4','@CALL()','  =CMD()'] as $bad) test(str_starts_with(KGP_Operator_Inquiry_Export::cell($bad),"'"),'CSV formulas cannot execute.');
test(KGP_Operator_Inquiry_Export::cell('ordinary text')==='ordinary text','Ordinary CSV text remains unmodified.');
echo "PASS: disabled-by-default, bilingual purpose/retention policy gates, opaque receipt token, formula-safe CSV.\n";

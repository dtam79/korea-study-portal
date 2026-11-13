<?php
/** Independent file and security contract test. */
$root=dirname(__DIR__);
$paths=['inc/inquiry/class-settings.php','inc/inquiry/class-schema.php','inc/inquiry/class-inquiries.php',
'inc/inquiry/class-routes.php','inc/inquiry/class-export.php','inc/inquiry/class-public.php','templates/contact.php',
'assets/inquiries.js','assets/contact.js','assets/contact.css'];
foreach($paths as $p) if(!is_file($root.'/'.$p)) throw new RuntimeException('Missing '.$p);
$main=file_get_contents($root.'/kg-portal-admin.php');
foreach(['class-inquiries.php','class-settings.php','class-public.php','kgpa_inquiry_retention_cleanup','admin_post_nopriv_kgpa_submit_inquiry'] as $x) if (!str_contains($main,$x)) throw new RuntimeException('Missing plugin hook '.$x);
$pub=file_get_contents($root.'/inc/inquiry/class-public.php');
foreach(['kgpa_contact_done','kgpa_form_','wp_verify_nonce','get_transient','wp_safe_redirect','KGP_Public_Catalog::university'] as $x) if (!str_contains($pub,$x)) throw new RuntimeException('Missing public safeguard '.$x);
$repo=file_get_contents($root.'/inc/inquiry/class-inquiries.php');
foreach(['consent_policy_version','delete_after','email_delivery_status','notification_failed','receipt_for_token','kgp_manage_inquiries','wp_salt','KGP_DB::transaction','KGP_Public_Catalog::offering'] as $x) if (!str_contains($repo,$x)) throw new RuntimeException('Missing inquiry safeguard '.$x);
$settings=file_get_contents($root.'/inc/inquiry/class-settings.php');
foreach(['intake_enabled','false','retention_days','purpose_ko','purpose_en','notice_ko','notice_en','deletion_contact'] as $x) if (!str_contains($settings,$x)) throw new RuntimeException('Missing settings gate '.$x);
$export=file_get_contents($root.'/inc/inquiry/class-export.php');
if (!str_contains($export,'kgp_manage_inquiries') || !str_contains($export,'check_admin_referer') || !str_contains($export,'receipt_code')) throw new RuntimeException('Private CSV export insecure.');
define('ABSPATH','/');
require $root.'/inc/inquiry/class-export.php';
foreach(['=1+1','+cmd','-NOW()','@SUM(1,1)',"  =HYPERLINK()"] as $evil) if (!str_starts_with(KGP_Operator_Inquiry_Export::cell($evil),"'")) throw new RuntimeException('Formula injection unescaped: '.$evil);
if (str_starts_with(KGP_Operator_Inquiry_Export::cell('safe@example.test'),"'")) throw new RuntimeException('Safe value altered.');
$test=file_get_contents($root.'/templates/contact.php');
foreach (['type="email"','minlength="10"','maxlength="2000"','type="checkbox"','form_token','kgpa-honeypot','kgpa-preferred-uni'] as $x) if (!str_contains($test,$x)) throw new RuntimeException('Public form missing '.$x);
echo "PASS: v0.8 inquiry routes, consent/retention gates, email and deletion model, role checks, formula-safe CSV, bilingual public form.\n";

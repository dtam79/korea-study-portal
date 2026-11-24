<?php
/** A02/A08/A09 read-only filter contracts; run via PHP CLI without a DB. */
define('ABSPATH', '/');
require __DIR__ . '/../inc/class-rest.php';
require __DIR__ . '/../inc/inquiry/class-settings.php';
require __DIR__ . '/../inc/inquiry/class-inquiries.php';
require __DIR__ . '/../inc/inquiry/class-export.php';
function kgpa_round3_assert(bool $value, string $description): void {
    if (!$value) throw new RuntimeException('FAIL: '.$description);
}
function kgpa_round3_invalid(callable $cb, string $description): void {
    try {$cb();} catch (InvalidArgumentException $e) {return;}
    throw new RuntimeException('FAIL: '.$description);
}
$empty=KGP_Operator_REST::entity_filters([], 'university');
kgpa_round3_assert($empty['sort']==='recent' && $empty['publication']==='', 'default university filters unchanged');
$f=KGP_Operator_REST::entity_filters(['region'=>'SEOUL', 'assignee'=>'13', 'publication'=>'pending','intakes'=>'without','sort'=>'name'],'university');
kgpa_round3_assert($f['region']==='SEOUL' && $f['sort']==='name', 'A02 supported advanced filters');
kgpa_round3_invalid(fn()=>KGP_Operator_REST::entity_filters(['region'=>"X' OR 1=1 --"],'university'),'SQL-like region rejected');
kgpa_round3_invalid(fn()=>KGP_Operator_REST::entity_filters(['assignee'=>'1; DROP TABLE'],'university'),'SQL-like assignee rejected');
kgpa_round3_invalid(fn()=>KGP_Operator_REST::entity_filters(['publication'=>'approved'],'university'),'ambiguous publication state rejected');
kgpa_round3_invalid(fn()=>KGP_Operator_REST::entity_filters(['sort'=>'name'],'program'),'university-only sort rejected for programs');
kgpa_round3_invalid(fn()=>KGP_Operator_REST::entity_filters(['intakes'=>'with'],'offering'),'unsupported cross-entity filter rejected');
$inq=KGP_Operator_Inquiries::list_filters(['language'=>'ko','assignee'=>'unassigned']);
kgpa_round3_assert($inq['language']==='ko' && $inq['assignee']==='unassigned','A08 private list language and unassigned filters');
kgpa_round3_assert(KGP_Operator_Inquiries::list_filters(['language'=>'en','assignee'=>'200'])['assignee']==='200','A08 numeric assigned user filter');
kgpa_round3_invalid(fn()=>KGP_Operator_Inquiries::list_filters(['language'=>'zh']),'unsupported public locale not invented');
kgpa_round3_invalid(fn()=>KGP_Operator_Inquiries::list_filters(['assignee'=>'0 OR 1=1']),'inquiry assignee injection blocked');
kgpa_round3_assert(str_starts_with(KGP_Operator_Inquiry_Export::cell("\u{200B}=1+1"),"'"),'CSV injection guard retained');
$src=file_get_contents(__DIR__.'/../inc/class-rest.php');
kgpa_round3_assert(str_contains($src,"current_user_can('manage_options') && current_user_can('kgp_manage_assignments')"),'operational details require WP administrator');
kgpa_round3_assert(!str_contains($src,'get_option(\'GEMINI_API_KEY\'') && str_contains($src,"'plugin_licenses' => 'not_verified'"),'A09 does not retrieve keys/licenses');
echo "PASS: A02 scoped filter validation, A08 safe filter contracts, CSV protection, A09 no-secret read-only boundary.\n";

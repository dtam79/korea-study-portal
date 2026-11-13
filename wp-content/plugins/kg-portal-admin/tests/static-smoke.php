<?php
/** Pure static checks: php tests/static-smoke.php */
$root=dirname(__DIR__);
$checks = [
    'independent plugin entry point'=>'kg-portal-admin.php',
    'menu and roles'=>'inc/class-admin.php',
    'reviewer-only queue'=>'inc/class-rest.php',
    'operator interface'=>'assets/admin.js',
    'admin styles'=>'assets/admin.css',
    'operator setup instructions'=>'README.md',
];
$errors=[];
foreach ($checks as $label=>$path) if (!is_file($root.'/'.$path)) $errors[]="Missing $label";
$entry=file_get_contents($root.'/kg-portal-admin.php');
$rest=file_get_contents($root.'/inc/class-rest.php');
$js=file_get_contents($root.'/assets/admin.js');
$admin=file_get_contents($root.'/inc/class-admin.php');
$assert=static function ($ok,$message)use(&$errors){if(!$ok)$errors[]=$message;};
$assert(str_contains($entry,'Requires Plugins: kg-portal-core'),'Core plugin dependency missing');
$assert(str_contains($rest,"current_user_can('kgp_review_entries')"),'Reviewer capability gate missing');
$assert(str_contains($rest,'user_assignments') && str_contains($rest,'role_code'),'Assigned editor scoping missing');
$assert(str_contains($rest,'field_version_sources'),'Field evidence review links missing');
$assert(str_contains($rest,'permission_callback'),'REST permission callback missing');
$assert(str_contains($admin,"wp_create_nonce('wp_rest')"),'WordPress nonce missing');
$assert(str_contains($js,"'X-WP-Nonce':conf.nonce"),'Browser nonce header missing');
foreach (['admin/fields','admin/sources','admin/identities','admin/entities','admin/issuers','/publish','/reject','/unpublish','/verify'] as $path) {
    $assert(str_contains($js,$path),'Missing Core flow: '.$path);
}
$assert(str_contains($js,'function sourceEvidence('),'Official source preview missing');
$assert(str_contains($js,'reviewerConfirmed('),'Human review confirmation missing');
$assert(!str_contains($entry,'dbDelta('),'Unexpected schema mutation in admin addon');
if ($errors) {foreach($errors as $error)fwrite(STDERR,"FAIL: $error\n"); exit(1);}
echo "PASS: plugin dependency, capability gates, editor scopes, source provenance, approval flow, REST nonce, zero schema changes.\n";

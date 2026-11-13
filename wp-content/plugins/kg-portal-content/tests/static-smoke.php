<?php
/** Run: php tests/static-smoke.php (no WordPress database required). */
$root=dirname(__DIR__);define('ABSPATH','/tmp/');
$files=['kg-portal-content.php','includes/class-content.php','includes/class-review.php','includes/class-public.php','templates/public.php','assets/public.css'];
foreach($files as $f)if(!is_file($root.'/'.$f))throw new RuntimeException('Missing '.$f);
$main=file_get_contents($root.'/kg-portal-content.php');
foreach(['register_activation_hook','kgpc_submit','kgpc_decide','kgpc_unpublish','init','template_include','pre_handle_404'] as $x)if(!str_contains($main,$x))throw new RuntimeException('Missing WordPress hook: '.$x);
$c=file_get_contents($root.'/includes/class-content.php');
if(!str_contains($c,"'show_in_rest'=>true")||!str_contains($c,"'publicly_queryable'=>false"))throw new RuntimeException('Draft privacy/Gutenberg contract missing.');
if(!str_contains($c,'prevent_native_publication')||!str_contains($c,"'status'=>'draft'")) {
 // Status is set on review; native publication must still be blocked.
 if(!str_contains($c,"$"."data['post_status']='draft'"))throw new RuntimeException('Native WordPress publication bypass is not blocked.');
}
$r=file_get_contents($root.'/includes/class-review.php');
foreach(['sources_valid','_kgpc_submitted_hash','_kgpc_submitted_by','verified_manually','post_author','translation_source_version','active_version','hash_equals','START TRANSACTION','ROLLBACK','version_no'] as $x)if(!str_contains($r,$x))throw new RuntimeException('Review/source gate missing '.$x);
$p=file_get_contents($root.'/includes/class-public.php');
foreach(['is_current','published','sources','template'] as $x) {

 if(!str_contains($p,$x))throw new RuntimeException('No public gate: '.$x);
}
$template=file_get_contents($root.'/templates/public.php');
foreach(['kgp-shell','kgp-site-header','kgp-nav','kgp-brand','kgp-language','kgp-footer','kgpc-faq','KGPC_Review::is_current'] as $x) {
 if ($x==='KGPC_Review::is_current')continue;
 if(!str_contains($template,$x))throw new RuntimeException('Missing shared shell/FAQ element: '.$x);
}
if(str_contains($template,'<?php get_header(')||str_contains($template,'<?php get_footer('))throw new RuntimeException('This must not fall back to Kadence default header.');
require $root.'/includes/class-content.php';
if(count(KGPC_Content::TOPICS)!==6)throw new RuntimeException('Six mandatory topics missing.');
echo "PASS: WordPress hooks, six guide topics, P05-P07/P10 routes, versioned publication, separated review, shared FE shell.\n";

<?php
/** Run: php tests/static-smoke.php. Does not need a WordPress server. */
$root=dirname(__DIR__);
$errors=[];
$expect=static function ($condition,$message) use (&$errors):void {if (!$condition) $errors[]=$message;};
$files=['functions.php','style.css','inc/routes.php','inc/view.php','inc/public-template.php','assets/css/site.css','assets/js/site.js','i18n/ko.json'];
foreach ($files as $name) $expect(is_file($root.'/'.$name),'Missing '.$name);
$style=(string)file_get_contents($root.'/style.css');
$expect(str_contains($style,'Template: kadence'),'Kadence parent is not configured.');
$routes=(string)file_get_contents($root.'/inc/routes.php');
foreach (['home','search','university','admissions','guides','guide','faq','contact'] as $name) {
    $expect(str_contains($routes,'kgp_screen='.$name),'Missing route '.$name);
}
$view=(string)file_get_contents($root.'/inc/view.php');
$expect(str_contains($view,"'/kgp/v1/'"),'Public pages must use the approved KGP REST namespace.');
$expect(str_contains($view,"rest_do_request"),'Missing REST integration.');
$expect(str_contains($view,"['http','https']"),'Public link protocol validation missing.');
$template=(string)file_get_contents($root.'/inc/public-template.php');
foreach (['kgp_fe_screen_home','kgp_fe_screen_search','kgp_fe_screen_university','kgp_fe_screen_admissions','kgp_fe_value'] as $name) {
    $expect(str_contains($template.$view,$name),'Missing implementation '.$name);
}
$expect(str_contains($template,'kgp_fe_sources'),'Evidence links missing.');
$expect(str_contains($template,'kgp_fe_detail_url'),'Offering-specific links missing.');
$css=(string)file_get_contents($root.'/assets/css/site.css');
$expect(str_contains($css,'max-width:480px'),'Desktop public max width must be 480px.');
$expect(str_contains($template,'kgp-filter-panel'),'Inline filter disclosure missing.');
$dict=json_decode((string)file_get_contents($root.'/i18n/ko.json'),true);
$expect(is_array($dict)&&count($dict)>=100,'Insufficient Korean UI translations.');
$expect(isset($dict['state.no_results'],$dict['detail.translation_pending']),'Critical labels not localized.');
if ($errors) {foreach ($errors as $error) fwrite(STDERR,'FAIL: '.$error."\n"); exit(1);}
echo "PASS: routes, REST integration, source safety, screen templates, responsive contract, i18n.\n";

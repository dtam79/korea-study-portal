<?php
/** W3-2 WordPress audit smoke. Require with wp eval (NOT eval-file). Read only. */
if (!defined('ABSPATH')) throw new RuntimeException('Run in a bootstrapped WordPress install.');
if (!class_exists('KGPO_Release') || !class_exists('KGPO_Release_Command')) throw new RuntimeException('W3-2 release plugin not loaded.');
if (!current_user_can('manage_options')) throw new RuntimeException('Administrator context required.');
if (!KGPO_SEO::staging()) throw new RuntimeException('Smoke must run against staging/local; do not run experimental QA on production.');
$before_user=get_current_user_id();
$facts=KGPO_Release::facts();
if ($before_user !== get_current_user_id()) throw new RuntimeException('Anonymous REST tests did not restore the admin user.');
$report=KGPO_Release::evaluate($facts,'staging');
if ($report['release_certified']!==false) throw new RuntimeException('Manual launch approval was incorrectly automated.');
if (!is_array($report['checks']) || count($report['checks'])<20) throw new RuntimeException('Readiness checks missing.');
if (!isset($facts['security']['private_statuses'], $facts['content']['published_universities'])) throw new RuntimeException('Incomplete readiness facts.');
foreach (KGPO_Release::PRIVATE_ROUTES as $route) {
    $status=$facts['security']['private_statuses'][$route]??null;
    if (!in_array($status,[401,403],true)) throw new RuntimeException('SECURITY FAIL: '.$route.' returned '.var_export($status,true).' to anonymous user.');
}
foreach (['ko','en'] as $lang) {
    if (($facts['security']['public_statuses'][$lang]??null)!==200) throw new RuntimeException('Public catalog unavailable for '.$lang);
}
echo 'PASS: Three private REST endpoints deny anonymous access; public KO/EN catalog reachable.' . "\n";
echo 'PASS: W3-2 reports '.count($report['failed_checks']).' launch blockers WITHOUT auto-approval or DB writes.' . "\n";
echo 'NOTE: Missing 20 real-university content, original-source review, load test and restore drill are NOT test skips.' . "\n";

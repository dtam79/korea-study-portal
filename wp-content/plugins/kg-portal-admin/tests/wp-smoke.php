<?php
/** Local DDEV integration: ddev wp --user=YOUR_ADMIN eval-file wp-content/plugins/kg-portal-admin/tests/wp-smoke.php */
if (!defined('ABSPATH')) {fwrite(STDERR,"Run through WordPress CLI.\n");exit(1);}
function kgpa_assert($condition,$message): void {
    if (!$condition) WP_CLI::error('FAILED: '.$message);
    WP_CLI::log('PASS: '.$message);
}
kgpa_assert(current_user_can('manage_options'),'Admin context selected using --user');
kgpa_assert(KGP_Operator_Admin::ready(),'Core v0.4.0 or later is active');
kgpa_assert(KGP_Schema::status()['ok'],'Core schema is complete');
$routes=rest_get_server()->get_routes();
foreach(['/kgp-operator/v1/health','/kgp-operator/v1/catalog','/kgp-operator/v1/entities','/kgp-operator/v1/queue','/kgp-operator/v1/versions/(?P<id>\d+)'] as $path) {
    kgpa_assert(array_key_exists($path,$routes),'Private REST endpoint registered: '.$path);
}
$health=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/health'));
kgpa_assert($health->get_status()===200 && !empty($health->get_data()['db_complete']),'Authorized health response');
$cat=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/catalog'));
kgpa_assert($cat->get_status()===200 && count($cat->get_data()['sections']??[])===14,'All 14 DET sections returned privately');
$list=new WP_REST_Request('GET','/kgp-operator/v1/entities');
$list->set_query_params(['type'=>'university','page'=>1,'per_page'=>10]);
$r=rest_do_request($list);
kgpa_assert($r->get_status()===200 && isset($r->get_data()['items'],$r->get_data()['total']),'Admin entity index works');
$review=new WP_REST_Request('GET','/kgp-operator/v1/queue');
$review->set_query_params(['kind'=>'field','page'=>1]);
$r=rest_do_request($review);
kgpa_assert($r->get_status()===200 && isset($r->get_data()['items'],$r->get_data()['total']),'Reviewer queue returns structured results');
$user=get_current_user_id();
try {
    wp_set_current_user(0);
    $denied=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/catalog'));
    kgpa_assert($denied->get_status()===401 || $denied->get_status()===403,'Anonymous access denied');
} finally {wp_set_current_user($user);}
WP_CLI::success('Operator admin integration smoke passed. No database changes.');

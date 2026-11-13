<?php
/** ddev wp --user=localadmin eval-file wp-content/plugins/kg-portal-ops/tests/wp-smoke.php */
if(!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI)exit(1);
function kgpo_test($condition,string $label): void {
    if(!$condition)WP_CLI::error('FAIL: '.$label);
    WP_CLI::log('PASS: '.$label);
}
kgpo_test(current_user_can('manage_options'),'WordPress administrator selected');
kgpo_test(KGPO_Schema::ready(),'Three additive operations tables installed');
kgpo_test(KGPO_Policy::core_ready(),'KGP Core source service loaded');
global $wpdb;
foreach(['source_state','source_checks','review_tasks'] as $key) {
    $name=KGPO_Schema::table($key);
    kgpo_test($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$name))===$name,'Table '.$key.' exists');
}
kgpo_test((bool)wp_next_scheduled('kgpo_scan_due'),'Bounded hourly source scan is scheduled');
$paths=['/kgpo/v1/overview','/kgpo/v1/sources','/kgpo/v1/tasks','/kgpo/v1/settings','/kgpo/v1/seo/preview'];
$routes=rest_get_server()->get_routes();
foreach($paths as $path)kgpo_test(isset($routes[$path]),'Private REST route '.$path);
$preview=rest_do_request(new WP_REST_Request('GET','/kgpo/v1/seo/preview'));
kgpo_test($preview->get_status()===200 && isset($preview->get_data()['published_universities']),'Authorized SEO read succeeds');
$old=get_current_user_id();
try {
    wp_set_current_user(0);
    $denied=rest_do_request(new WP_REST_Request('GET','/kgpo/v1/sources'));
    kgpo_test($denied->get_status()===401||$denied->get_status()===403,'Anonymous private monitor access denied');
}finally{wp_set_current_user($old);}
kgpo_test(KGPO_SEO::staging(),'DDEV preview is not indexable');
kgpo_test(KGPO_SEO::entries()===[],'No source/sitemap data is exposed to search engines on DDEV');
WP_CLI::success('Operations smoke passed. No source HTTP requests or Core data writes made.');

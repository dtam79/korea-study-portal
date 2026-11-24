<?php
/** Run using `ddev wp --user=ADMIN_ID eval 'require ABSPATH . "...";'`. DB read-only. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(1);
function kgpa_r3_ok(bool $ok,string $message): void {if(!$ok)WP_CLI::error('FAIL '.$message);WP_CLI::log('PASS '.$message);}
kgpa_r3_ok(current_user_can('manage_options') && current_user_can('kgp_manage_assignments'),'Administrator context');
$routes=rest_get_server()->get_routes();
kgpa_r3_ok(isset($routes['/kgp-operator/v1/operations']),'Operations route registered');
$ops=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/operations'));
kgpa_r3_ok($ops->get_status()===200 && ($ops->get_data()['admin_version']??'')==='0.8.6','A09 admin-only operational status');
kgpa_r3_ok(!isset($ops->get_data()['gemini_api_key'], $ops->get_data()['smtp_password']),'No configuration secrets returned');
$ok=new WP_REST_Request('GET','/kgp-operator/v1/entities');
$ok->set_query_params(['type'=>'university','region'=>'SEOUL','intakes'=>'with','page'=>1,'per_page'=>5]);
$rows=rest_do_request($ok);
kgpa_r3_ok($rows->get_status()===200 && isset($rows->get_data()['items'],$rows->get_data()['total']),'A02 read-only filtered list');
$bad=new WP_REST_Request('GET','/kgp-operator/v1/entities');
$bad->set_query_params(['type'=>'university','region'=>"SEOUL' OR 1=1",'page'=>1]);
kgpa_r3_ok(rest_do_request($bad)->get_status()===422,'A02 invalid filter rejected');
$bad=new WP_REST_Request('GET','/kgp-operator/v1/inquiries');
$bad->set_query_params(['language'=>'fr','page'=>1]);
kgpa_r3_ok(rest_do_request($bad)->get_status()===422,'A08 unsupported language rejected');
$valid=new WP_REST_Request('GET','/kgp-operator/v1/inquiries');
$valid->set_query_params(['language'=>'en','assignee'=>'unassigned','page'=>1]);
kgpa_r3_ok(rest_do_request($valid)->get_status()===200,'A08 filtered private list');
$user=get_current_user_id();
try {wp_set_current_user(0);$guest=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/operations'));
    kgpa_r3_ok($guest->get_status()===401||$guest->get_status()===403,'Unauthorized operations read denied');
    $guest=rest_do_request(new WP_REST_Request('GET','/kgp-operator/v1/inquiries'));
    kgpa_r3_ok($guest->get_status()===401||$guest->get_status()===403,'Unauthorized inquiry listing denied');
} finally {wp_set_current_user($user);}
WP_CLI::success('A02/A08/A09 read-only integration passed; no writes.');

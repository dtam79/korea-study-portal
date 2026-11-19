<?php
/** Read-only WP integration smoke; invoke with `ddev wp eval 'require ...';` (not eval-file). */
if (!defined('ABSPATH')) exit(1);
$check=static function(bool $ok,string $label):void{
    if(!$ok) WP_CLI::error('UI v1.2 FAIL: '.$label);
    WP_CLI::log('PASS: '.$label);
};
$check(function_exists('kgp_fe_ui_query') && function_exists('kgp_fe_applied_summary'), 'P02/P04 UI filter helpers loaded');
$check(is_file(get_stylesheet_directory().'/partials/kgp-header.php'), 'Shared C01 header partial installed in active child theme');
$check(defined('KGP_FE_VERSION') && KGP_FE_VERSION==='0.5.3', 'Correct child theme release');
$check(defined('KGDS_VERSION') && KGDS_VERSION==='2.1.5', 'Correct UI design release');
$check(defined('KGP_ADMIN_VERSION') && KGP_ADMIN_VERSION==='0.8.3', 'Contact UI package installed');
$check(defined('KGPC_VERSION') && KGPC_VERSION==='0.9.3', 'Content source disclosure package installed');
$p=kgp_fe_ui_query(['q'=>'KAIST','certified'=>'0','sort'=>'invalid','page'=>'0']);
$check(($p['params']['certified']??null)==='0' && !isset($p['params']['sort'],$p['params']['page']), 'URL normalization preserves false and rejects unsupported sort/page');
$req=new WP_REST_Request('GET','/kgp/v1/catalog');
$req->set_query_params(['locale'=>'ko','page'=>1]);
$resp=rest_do_request($req);
$check(!is_wp_error($resp) && $resp->get_status()===200, 'Original approved public catalog API remains available without DB writes');
$data=$resp->get_data();
$check(is_array($data) && isset($data['items'],$data['total']), 'Original catalog result shape preserved');
$check(is_array($data['items']) && count($data['items'])<=12, 'P02 approved-offering page size remains 12');
WP_CLI::log('PASS: UI v1.2 integration. Existing data/approval untouched. This is not a browser acceptance test.');

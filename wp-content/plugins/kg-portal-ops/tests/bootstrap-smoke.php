<?php
/** Offline bootstrap and hook registration verification; no DB changes. */
define('ABSPATH','/');
function plugin_dir_path($file){return dirname($file).'/';}
$actions=[];$filters=[];$activations=[];
function register_activation_hook($file,$callback){global $activations;$activations[]=$callback;}
function register_deactivation_hook($file,$callback){}
function add_action($name,$fn,$priority=10){global $actions;$actions[$name][]=$fn;}
function add_filter($name,$fn,$priority=10,$args=1){global $filters;$filters[$name][]=$fn;}
require dirname(__DIR__).'/kg-portal-ops.php';
if (KGPO_VERSION!=='1.0.0')throw new RuntimeException('Wrong version.');
foreach (['KGPO_Schema','KGPO_Policy','KGPO_Monitor','KGPO_Admin','KGPO_REST','KGPO_SEO','KGPO_CLI'] as $class) {
    if (!class_exists($class))throw new RuntimeException('Missing class '.$class);
}
foreach(['kgpo_scan_due','rest_api_init','template_redirect','wp_head','init'] as $hook){
    if(empty($actions[$hook]))throw new RuntimeException('Missing action '.$hook);
}
foreach(['wp_robots','wp_sitemaps_enabled','robots_txt','pre_handle_404'] as $hook){
    if(empty($filters[$hook]))throw new RuntimeException('Missing filter '.$hook);
}
if(count($activations)!==1)throw new RuntimeException('One additive schema activation is expected.');
echo "PASS: plugin loads, safe cron, REST, approved-only sitemap and search-index hook registrations.\n";

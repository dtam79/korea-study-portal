<?php
/** Verify plugin registration without WordPress or a database. */
define('ABSPATH','/');
function plugin_dir_path($file){return dirname($file).'/';}
$actions=[];$filters=[];$activation=[];
function add_action($hook,$callback,$priority=10){global $actions;$actions[$hook][]=$callback;}
function add_filter($hook,$callback,$priority=10){global $filters;$filters[$hook][]=$callback;}
function register_activation_hook($file,$callback){global $activation;$activation[]=$callback;}
function register_deactivation_hook($file,$callback){}
require dirname(__DIR__).'/kg-portal-admin.php';
if (KGP_ADMIN_VERSION!=='0.8.0') throw new RuntimeException('Wrong plugin version.');
foreach(['KGP_Operator_Admin','KGP_Operator_Workflow_Routes','KGP_Operator_Workflow_Schema',
    'KGP_Operator_Inquiry_Settings','KGP_Operator_Inquiry_Schema','KGP_Operator_Inquiries',
    'KGP_Operator_Inquiry_Routes','KGP_Operator_Contact_Pages','KGP_Operator_Inquiry_Export'] as $class) {
    if(!class_exists($class))throw new RuntimeException('Failed to load '.$class);
}
foreach (['admin_post_nopriv_kgpa_submit_inquiry','admin_post_kgpa_submit_inquiry',
    'admin_post_kgpa_export_inquiries','kgpa_inquiry_retention_cleanup','rest_api_init'] as $hook) {
    if(empty($actions[$hook]))throw new RuntimeException('Missing WordPress hook '.$hook);
}
if (count($activation)<2) throw new RuntimeException('Missing additive migration activation hooks.');
echo "PASS: v0.8 plugin load, prior v0.7 workflow registration, private/public hooks, additive activation migrations.\n";

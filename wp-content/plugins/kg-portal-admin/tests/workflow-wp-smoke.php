<?php
/** Local WordPress/DDEV integration smoke; requires --user=YOUR_ADMIN. */
if (!defined('ABSPATH')) exit(1);
function kgpa_assert($condition,string $label):void {if (!$condition) WP_CLI::error('FAIL: '.$label);WP_CLI::log('PASS: '.$label);}
kgpa_assert(KGP_Operator_Admin::ready(),'Core domain services available');
kgpa_assert(current_user_can('manage_options'),'Run as WordPress administrator');
kgpa_assert(get_option('kgpa_workflow_schema_version')===KGP_Operator_Workflow_Schema::VERSION,'Addon schema installed');
$routes=rest_get_server()->get_routes();
foreach(['/kgp-operator/v1/translations/queue','/kgp-operator/v1/csv/preview','/kgp-operator/v1/csv/batches'] as $path) {
    kgpa_assert(isset($routes[$path]),'Registered endpoint '.$path);
}
global $wpdb;
foreach(['translation_drafts','csv_batches','csv_rows'] as $table) {
    $name=KGP_Operator_Workflow_Schema::table($table);
    kgpa_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$name))===$name,'Table '.$table.' exists');
}
class KGP_Operator_Intentional_Rollback extends RuntimeException {}
try {
    KGP_DB::transaction(static function(){
        $csv="entity_type,public_id,official_name,issuer_type\nissuer,QA-".str_replace('-','',wp_generate_uuid4()).",Example Test Issuer,university\n";
        $created=KGP_Operator_CSV::preview($csv,'qa-only.csv');
        if (count($created['rows'])!==1 || $created['rows'][0]['status']!=='ready') throw new RuntimeException('Preview row not ready.');
        throw new KGP_Operator_Intentional_Rollback('rollback');
    });
} catch(KGP_Operator_Intentional_Rollback $e) {
    kgpa_assert(true,'CSV preview staged and rolled back without creating university data');
}
$anon=get_current_user_id();wp_set_current_user(0);
try {
    $req=new WP_REST_Request('GET','/kgp-operator/v1/csv/batches');
    $result=rest_do_request($req);
    kgpa_assert($result->get_status()>=400,'Anonymous visitor cannot list private imports');
} finally {wp_set_current_user($anon);}
WP_CLI::success('Workflow smoke passed. CSV test data was rolled back.');

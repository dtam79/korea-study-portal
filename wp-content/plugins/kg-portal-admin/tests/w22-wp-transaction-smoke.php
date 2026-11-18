<?php
/** W2-2 DDEV-only transaction smoke; makes NO persistent university/import data. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(1);
function w22_wp_test(bool $ok,string $name):void {if (!$ok) throw new RuntimeException('FAIL: '.$name); WP_CLI::log('PASS: '.$name);}
$host=(string)wp_parse_url(home_url(),PHP_URL_HOST);
if (!str_ends_with($host,'.ddev.site')) WP_CLI::error('This integration smoke must run on local *.ddev.site.');
w22_wp_test(current_user_can('manage_options') && current_user_can('kgp_manage_issuers') && current_user_can('kgp_edit_entries'),'Administrator has import and evidence capabilities');
w22_wp_test(get_option('kgpa_workflow_schema_version')===KGP_Operator_Workflow_Schema::VERSION,'Workflow schema is installed');
$routes=rest_get_server()->get_routes();
w22_wp_test(isset($routes['/kgp-operator/v1/csv/(?P<id>\\d+)/refresh']),'CSV preview refresh route registered');
class W22TestRollback extends RuntimeException {}
try {
    KGP_DB::transaction(static function(){
        $suffix=str_replace('-','',wp_generate_uuid4());
        $pid='QA-W22-'.substr($suffix,0,16);
        $csv="entity_type,public_id,official_name,issuer_type\nissuer,$pid,Synthetic W22 Issuer,university\n";
        $before=KGP_Operator_CSV::preview($csv,'w22-local-test.csv');
        w22_wp_test(count($before['rows'])===1 && $before['rows'][0]['status']==='ready','Preview is ready without Core data writes');
        $row_id=(int)$before['rows'][0]['id'];$batch_id=(int)$before['batch']['id'];
        $created=KGP_Evidence_Repository::create_issuer(['public_id'=>$pid,'official_name'=>'Synthetic W22 Issuer','issuer_type'=>'university']);
        w22_wp_test($created>0,'A second operator-like write changes the preview baseline');
        $stale=KGP_Operator_CSV::apply($batch_id,[$row_id]);
        w22_wp_test($stale['failed']===1 && $stale['applied']===0,'Stale CSV row cannot silently overwrite changed data');
        $afterConflict=KGP_Operator_CSV::get_batch($batch_id);
        w22_wp_test($afterConflict['rows'][0]['status']==='ready','Stale row remains refreshable rather than permanently unusable');
        $refreshed=KGP_Operator_CSV::refresh($batch_id);
        w22_wp_test($refreshed['refreshed']===1 && $refreshed['failed']===0,'Explicit refresh recaptures current row');
        $ok=KGP_Operator_CSV::apply($batch_id,[$row_id]);
        w22_wp_test($ok['failed']===0 && $ok['kept']===1,'Re-reviewed unchanged data is applied idempotently');
        $anon=get_current_user_id();wp_set_current_user(0);
        try {
            $r=new WP_REST_Request('POST','/kgp-operator/v1/csv/'.$batch_id.'/refresh');
            $response=rest_do_request($r);
            w22_wp_test($response->get_status()>=400,'Anonymous actor cannot refresh private CSV preview');
        } finally {wp_set_current_user($anon);}
        throw new W22TestRollback('Rollback synthetic W2-2 data');
    });
} catch(W22TestRollback $e) {
    WP_CLI::log('PASS: All synthetic records and revisions rolled back.');
}
WP_CLI::success('W2-2 WordPress CSV race/refresh smoke passed. No persisted test data.');

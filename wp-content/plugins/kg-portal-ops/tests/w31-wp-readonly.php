<?php
/** Read-only DDEV smoke, invoked via wp eval require (NOT wp eval-file). */
if (!defined('ABSPATH')){fwrite(STDERR,"WordPress must be loaded.\n");exit(1);}
if (!class_exists('KGPO_SEO') || !class_exists('KGP_Public_Catalog'))throw new RuntimeException('Core/operations plugins are inactive.');
if (!function_exists('rest_do_request'))throw new RuntimeException('REST API unavailable.');
$previous=get_current_user_id();
wp_set_current_user(0);
try {
    $private=rest_do_request(new WP_REST_Request('GET','/kgp/v1/admin/summary'));
    if(!in_array($private->get_status(),[401,403],true))throw new RuntimeException('SECURITY FAIL: Anonymous admin REST access is not denied.');
    echo "PASS: anonymous private REST rejected with HTTP ".$private->get_status()."\n";
    $request=new WP_REST_Request('GET','/kgp/v1/catalog');
    $request->set_param('locale','ko');
    $start=microtime(true);$queries_before=(int)$GLOBALS['wpdb']->num_queries;
    $response=rest_do_request($request);
    $ms=round(1000*(microtime(true)-$start));
    $queries=(int)$GLOBALS['wpdb']->num_queries-$queries_before;
    if($response->get_status()!==200)throw new RuntimeException('Public search REST failed: '.$response->get_status());
    $data=$response->get_data();
    if(!is_array($data)||!isset($data['items'],$data['total']))throw new RuntimeException('Public search response shape changed.');
    echo "PASS: public search /ko — {$data['total']} offerings, {$ms} ms, {$queries} SQL queries (single local sample; NOT p95).\n";
    if((int)$data['total']<1){echo "SKIP: no published offering; 2-real-university launch validation remains pending.\n";}
    else {
        $first=$data['items'][0]??null;
        if(!is_array($first)||empty($first['offering_id'])||empty($first['university_id']))throw new RuntimeException('Approved card is incomplete.');
        $d=KGP_Public_Catalog::offering((string)$first['offering_id'],'ko');
        $en=KGP_Public_Catalog::offering((string)$first['offering_id'],'en');
        if(!$d||!$en||($d['university_id']??null)!==$first['university_id']||($en['university_id']??null)!==$d['university_id']){
            throw new RuntimeException('SECURITY/INTEGRITY FAIL: published offering identity differs between search, detail and languages.');
        }
        echo "PASS: first published KO/EN detail remains on the same offering identity.\n";
    }
    if(KGPO_SEO::staging()){
        if(KGPO_SEO::entries()!==[])throw new RuntimeException('SECURITY FAIL: staging sitemap includes public entries.');
        echo "PASS: staging sitemap remains empty.\n";
    } else {echo "NOTE: site is marked production; do not run destructive QA on production.\n";}
    echo "NOTE: performance p95/20-concurrent/2,000-offering validation needs a separate staged load test.\n";
} finally {wp_set_current_user($previous);}

<?php
/** Run: ddev wp eval-file wp-content/themes/kg-portal-child/tests/wp-integration-smoke.php */
if (!defined('ABSPATH')) {fwrite(STDERR,"WordPress is required.\n");exit(1);}
function kgp_fe_test(bool $condition,string $name):void {
    if (!$condition) {fwrite(STDERR,'FAIL: '.$name."\n");exit(1);}
    echo 'PASS: '.$name."\n";
}
kgp_fe_test(wp_get_theme()->get_stylesheet()==='kg-portal-child','Child theme is active');
kgp_fe_test(defined('KGP_CORE_VERSION') && version_compare(KGP_CORE_VERSION,'0.4.0','>='),'KGP Core API v0.4+ is active');
$rules=(array)get_option('rewrite_rules');
kgp_fe_test((bool)array_filter(array_keys($rules),static fn($key)=>str_contains($key,'(ko|en)')),'Localized rewrite rules are installed');
$catalog=kgp_fe_api('catalog',['locale'=>'ko','page'=>1]);
kgp_fe_test($catalog['ok']&&isset($catalog['data']['items'],$catalog['data']['total']),'Public catalog API returns expected shape');
$admissions=kgp_fe_api('admissions',['locale'=>'en','page'=>1]);
kgp_fe_test($admissions['ok']&&isset($admissions['data']['items']),'Admissions API returns expected shape');
$invalid=kgp_fe_api('catalog',['sort'=>'tuition','locale'=>'ko']);
kgp_fe_test(!$invalid['ok']&&$invalid['status']===422,'Invalid tuition sorting is rejected server-side');
$clean=kgp_fe_filter_params(['region'=>['seoul','busan','seoul'],'field'=>'engineering','other'=>'ignore']);
kgp_fe_test(($clean['region']??'')==='seoul,busan'&&($clean['field']??'')==='engineering'&&!isset($clean['other']),'OR filters and whitelist correctly normalized');
kgp_fe_test(kgp_fe_safe_url('javascript:alert(1)')==='','Unsafe public URLs are suppressed');
kgp_fe_test(kgp_fe_price(['min'=>4300000.0,'max'=>4300000.0,'currency'=>'KRW','unit'=>'semester'])!=='','Original-unit tuition is displayed');
echo "Success: KGP FE integration smoke passed. No database rows were changed.\n";

<?php
/** W3-2 automated launch checks: fail closed, no WordPress required. */
define('ABSPATH','/');
require dirname(__DIR__).'/inc/class-release.php';
function w32_assert(bool $condition,string $label):void {
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    echo "PASS: $label\n";
}
$f=[
 'site'=>['environment'=>'production','scheme'=>'https','https'=>true,'staging_noindex'=>false,
          'staging_sitemap_empty'=>null,'indexable'=>true,'debug_display'=>false],
 'system'=>['core_schema'=>true,'content_schema'=>true,'ops_schema'=>true,
            'active_plugins'=>KGPO_Release::REQUIRED_PLUGINS,'integrity_ok'=>true,'integrity_issues'=>0,
            'cron_scheduled'=>true,'monitor_enabled'=>true,'open_review_tasks'=>0,'strict_no_cache'=>true],
 'content'=>['published_universities'=>20,'approved_offerings_ko'=>40,'approved_offerings_en'=>40,'pilot_ready'=>true,
             'language_topics'=>['ko'=>['guides'=>6,'faq_categories'=>5,'policies'=>3],
                                 'en'=>['guides'=>6,'faq_categories'=>5,'policies'=>3]]],
 'security'=>['private_statuses'=>array_fill_keys(KGPO_Release::PRIVATE_ROUTES,403),
              'public_statuses'=>['ko'=>200,'en'=>200]],
];
$r=KGPO_Release::evaluate($f,'production');
w32_assert($r['automated_ready']===true && count($r['checks'])>=20,'Complete, plausible production facts pass all AUTOMATED checks.');
w32_assert($r['release_certified']===false && count($r['manual_pending'])===6,'Automation cannot attest manual evidence or certify the launch.');
$f['site']['staging_noindex']=true;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('environment',$r['failed_checks'],true),'Production mode rejects a staging/noindex host.');
$f['site']['staging_noindex']=false;
$f['content']['approved_offerings_en']=39;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('offerings',$r['failed_checks'],true),'KO/EN Offering count mismatch blocks release.');
$f['content']['approved_offerings_en']=40;
$f['content']['language_topics']['en']['policies']=2;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('content_en_policies',$r['failed_checks'],true),'Missing current EN policy is a blocker.');
$f['content']['language_topics']['en']['policies']=3;
$f['security']['private_statuses']['/kgpo/v1/overview']=404;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('anonymous_private',$r['failed_checks'],true),'Missing private REST route (404) is NOT incorrectly accepted as access control.');
$f['security']['private_statuses']['/kgpo/v1/overview']=200;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('anonymous_private',$r['failed_checks'],true),'Leaked anonymous 200 is rejected.');
$f['security']['private_statuses']['/kgpo/v1/overview']=403;
$f['system']['open_review_tasks']=1;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('pending_reviews',$r['failed_checks'],true),'Unresolved review queue blocks release.');
$f['system']['open_review_tasks']=0;
$f['system']['integrity_ok']=false;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('data_integrity',$r['failed_checks'],true),'Incomplete real data audit blocks release.');
$f['system']['integrity_ok']=true;
$f['system']['strict_no_cache']=false;
$r=KGPO_Release::evaluate($f,'production');
w32_assert(in_array('safe_cache',$r['failed_checks'],true),'Untested public caching blocks conservative launch.');
$f['system']['strict_no_cache']=true;
$f['site']['environment']='local';$f['site']['staging_noindex']=true;$f['site']['staging_sitemap_empty']=true;
$r=KGPO_Release::evaluate($f,'local');
w32_assert($r['automated_ready']===true && $r['release_certified']===false,'Local staging gate passes only for its own mode; no auto production signoff.');
$f['site']['staging_sitemap_empty']=false;
$r=KGPO_Release::evaluate($f,'local');
w32_assert(in_array('indexing',$r['failed_checks'],true),'Non-production sitemap leakage blocks stage gate.');
$thrown=false;
try{KGPO_Release::evaluate($f,'production',0,20);}catch(InvalidArgumentException $e){$thrown=true;}
w32_assert($thrown,'Minima cannot be set to zero to bypass content gates.');
echo "SUCCESS: W3-2 offline launch gate checks passed.\n";

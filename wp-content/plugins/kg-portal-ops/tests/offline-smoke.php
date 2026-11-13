<?php
/** Test HTTP hint classification, SSRF gates, no unreviewed SEO content. No WP required. */
define('ABSPATH','/');define('DAY_IN_SECONDS',86400);
function wp_parse_url($s,$component=-1){return parse_url($s,$component);}
function wp_http_validate_url($u){
    $h=parse_url($u,PHP_URL_HOST);
    return (is_string($h)&&!in_array(strtolower($h),['localhost','127.0.0.1','10.0.0.1','169.254.169.254','192.168.1.1'],true))?$u:false;
}
function wp_get_environment_type(){return 'production';}
function home_url($path=''){return 'https://portal.example.org'.$path;}
function get_option($key,$default=[]){return $default;}
function wp_safe_remote_get($url,$args){
    if(!empty($GLOBALS['response']))return $GLOBALS['response'];
    return ['response'=>['code'=>200],'headers'=>['etag'=>'"v1"','last-modified'=>'Wed, 11 Nov 2026 00:00:00 GMT'],'body'=>'<html>Example official public content</html>'];
}
function is_wp_error($response){return $response instanceof Exception;}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function wp_remote_retrieve_header($r,$name){return $r['headers'][$name]??null;}
function wp_remote_retrieve_body($r){return $r['body']??'';}
function ensure($condition,$label){if(!$condition)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
require dirname(__DIR__).'/inc/class-schema.php';
require dirname(__DIR__).'/inc/class-policy.php';
require dirname(__DIR__).'/inc/class-monitor.php';
require dirname(__DIR__).'/inc/class-seo.php';
foreach(['file:///etc/passwd','http://localhost/admin','http://127.0.0.1/private','http://10.0.0.1/','http://169.254.169.254/latest/meta-data','https://example.org:8081/a','https://user:pass@example.org/'] as $bad){
    ensure(!KGPO_Monitor::safe_public_url($bad),'Unsafe URL rejected: '.$bad);
}
ensure(KGPO_Monitor::safe_public_url('https://example.org/admissions.pdf'),'Safe public domain accepted');
$first=KGPO_Monitor::probe('https://example.org/admissions.pdf');
ensure($first['ok'] && $first['status']===200 && $first['hash']===hash('sha256','<html>Example official public content</html>'),'Probe records only limited fingerprint');
ensure(KGPO_Monitor::classify(null,$first)==='baseline','First retrieval becomes baseline, not factual approval');
$prior=['etag'=>$first['etag'],'last_modified'=>$first['modified'],'content_hash'=>$first['hash'],
    'sample_size'=>$first['size'],'baseline_created_at'=>'2026-11-12 00:00:00'];
ensure(KGPO_Monitor::classify($prior,$first)==='unchanged','Matching fingerprints leave monitoring unchanged');
$changed=$first;$changed['etag']='"v2"';$changed['hash']=hash('sha256','updated');
ensure(KGPO_Monitor::classify($prior,$changed)==='changed','ETag or hash change requires human review');
$GLOBALS['response']=['response'=>['code'=>304],'headers'=>[],'body'=>''];
$notModified=KGPO_Monitor::probe('https://example.org/admissions.pdf',$prior);
ensure($notModified['status']===304 && KGPO_Monitor::classify($prior,$notModified)==='unchanged','Conditional GET handles 304');
$GLOBALS['response']=['response'=>['code'=>404],'headers'=>[],'body'=>''];
$missing=KGPO_Monitor::probe('https://example.org/admissions.pdf',$prior);
ensure(KGPO_Monitor::classify($prior,$missing)==='unavailable','404 triggers availability review, not automatic unpublish');
ensure(KGPO_SEO::robots('User-agent: *',false)==="User-agent: *\nSitemap: https://portal.example.org/kgp-sitemap.xml\n",'Production robots includes sitemap');
$ref=new ReflectionMethod(KGPO_SEO::class,'row_xml');
$xml=$ref->invoke(null,['url'=>'https://example.org/?a=1&b=2','alternate'=>['ko'=>'https://example.org/ko/?x=1&y=2']]);
ensure(str_contains($xml,'&amp;') && str_contains($xml,'hreflang="ko"'),'XML sitemap escapes URLs safely');
ensure(count(KGPO_SEO::entries())===6,'Only six static FE routes without installed verified content');
echo "PASS: all offline source-monitoring and SEO checks complete.\n";

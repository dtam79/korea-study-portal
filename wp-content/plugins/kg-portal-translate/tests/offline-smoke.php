<?php
/** Offline contracts; the adapter is mocked, so NO request to Google is made. */
define('ABSPATH','/');
define('KGPT_VERSION','0.1.0');
define('KGP_GEMINI_API_KEY','unit-test-key-do-not-use');
$transients=[]; $http_calls=[];
function wp_json_encode($value,int $options=0) {return json_encode($value,$options);}
function get_transient(string $key) {global $transients; return $transients[$key]??false;}
function set_transient(string $key,$value,int $ttl) {global $transients; $transients[$key]=$value;return true;}
function rest_url(string $route) {return 'https://example.test/wp-json/'.$route;}
function wp_remote_post(string $url,array $args) {
    global $http_calls;$http_calls[]=['url'=>$url,'args'=>$args];
    $body=json_decode($args['body'],true);
    $data=json_decode($body['contents'][0]['parts'][0]['text'],true);
    $texts=$data['texts'];
    return ['response'=>['code'=>200], 'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[
        ['text'=>json_encode(['translations'=>array_map(static fn($s)=>'FR:'.$s,$texts)])]
    ]]]]])];
}
function is_wp_error($x) {return $x instanceof WP_Error;}
function wp_remote_retrieve_response_code($r) {return $r['response']['code'];}
function wp_remote_retrieve_body($r) {return $r['body'];}
class WP_Error {
    public function __construct(public $code, public $message, public $data=[]) {}
    public function get_error_code(){return $this->code;}
}
class UnitResponse {
    public array $headers=[];
    public function __construct(public $data) {}
    public function header($key,$value){$this->headers[$key]=$value;}
}
function rest_ensure_response($data) {return new UnitResponse($data);}
class UnitRequest {
    public function __construct(private array $params){}
    public function get_param($name){return $this->params[$name]??null;}
}
function assert_ok($yes,string $name):void {
    if (!$yes)throw new RuntimeException('FAIL: '.$name);
    echo 'PASS: '.$name."\n";
}
require dirname(__DIR__).'/inc/class-gemini.php';
require dirname(__DIR__).'/inc/class-translation.php';
assert_ok(count(KGPT_Translation::LOCALES)>=20 && KGPT_Translation::locale('vi-VN')==='vi' && KGPT_Translation::locale('zh-HK')==='zh-TW','BCP-47 normalization and supported languages');
assert_ok(KGPT_Translation::locale('en-US')==='en' && KGPT_Translation::locale('invalid-xyz')===null,'Unsupported locales not proxied');
$html='<!doctype html><html lang="en"><head><title>Private title</title></head><body><p>Outside approved shell</p>' .
  '<div class="kgp-shell"><header class="kgp-site-header"><a>Find universities</a></header>' .
  '<main><h1>Public study information</h1><div><strong>Approved information</strong></div>' .
  '<p>Tuition of 2027 KRW 3500</p><p>Public study information</p>' .
  '<script>window.secret="DO NOT SEND SCRIPT";</script><style>NEVER SEND CSS</style>' .
  '<input value="secret-student@example.com" placeholder="Search by university" />' .
  '<div class="kgpt-notice">Machine-only interface text</div>' .
  '<div hidden>Hidden private data</div><textarea>MY PRIVATE MESSAGE</textarea>' .
  '<div aria-hidden="true">SR hidden message</div>' .
  '<section data-kgpt-ignore="">Admin-only information</section>' .
  '<a aria-label="Read official source">Official source</a></main><footer class="kgp-footer">Study safely</footer></div></body></html>';
$texts=KGPT_Translation::extract($html);
foreach (['Find universities','Public study information','Approved information','Search by university','Read official source','Official source','Study safely'] as $text) {
    assert_ok(in_array($text,$texts,true),'Public text extracted: '.$text);
}
assert_ok(count(array_filter($texts,static fn($s)=>$s==='Public study information'))===1,'Repeated visible text deduplicated');
foreach (['Outside approved shell','DO NOT SEND SCRIPT','NEVER SEND CSS','Tuition of 2027 KRW 3500','secret-student@example.com','MY PRIVATE MESSAGE','Hidden private data','SR hidden message','Admin-only information','Machine-only interface text'] as $private) {
    assert_ok(!in_array($private,$texts,true),'Excluded input: '.$private);
}
assert_ok(!in_array('secret-student@example.com',$texts,true),'Raw form values cannot be sent');
assert_ok(KGPT_Gemini::safe_translation('Summer 2027','Été 2028')==='Summer 2027','Numbers never silently change');
assert_ok(KGPT_Gemini::safe_translation('Official information','<img src=x>')==='Official information','Provider markup rejected');
assert_ok(KGPT_Gemini::safe_translation('Official information','Informations officielles')==='Informations officielles','Valid translation accepted');
assert_ok(KGPT_Translation::chunks(array_fill(0,63,'Safe public sentence')) && count(KGPT_Translation::chunks(array_fill(0,63,'Safe public sentence')))===3,'Bounded provider chunks');
$source=['Find universities','Approved information'];
$manifest=hash('sha256',json_encode($source));
set_transient('kgpt_m_'.$manifest,['chunks'=>[$source],'created'=>time()],60);
$request = new UnitRequest(['manifest'=>$manifest,'language'=>'fr-FR','chunk'=>0,'texts'=>['PRIVATE INJECTION']]);
$result=KGPT_Translation::rest_translate($request);
assert_ok($result instanceof UnitResponse && $result->data['translations']['Find universities']==='FR:Find universities', 'Only stored public text translated');
assert_ok(count($http_calls)===1 && !str_contains($http_calls[0]['args']['body'],'PRIVATE INJECTION'),'Client-controlled texts never forwarded');
assert_ok(!str_contains(json_encode($result->data),'unit-test-key'), 'API key never in JSON reply');
$result=KGPT_Translation::rest_translate($request);
assert_ok(count($http_calls)===1,'Provider result reused from cache');
assert_ok($result->headers['Cache-Control']==='no-store','REST response is never browser-cached');
$bad=KGPT_Translation::rest_translate(new UnitRequest(['manifest'=>str_repeat('0',64),'language'=>'fr','chunk'=>0]));
assert_ok($bad instanceof WP_Error && $bad->get_error_code()==='kgpt_expired','Unknown server manifest rejected without API call');
$bad=KGPT_Translation::rest_translate(new UnitRequest(['manifest'=>$manifest,'language'=>'en','chunk'=>0]));
assert_ok($bad instanceof WP_Error && $bad->get_error_code()==='kgpt_bad_request','Approved KO/EN stay outside model');
$bad=KGPT_Translation::rest_translate(new UnitRequest(['manifest'=>$manifest,'language'=>'fr','chunk'=>'01']));
assert_ok($bad instanceof WP_Error,'Chunk index must be canonical integer');
assert_ok(count($http_calls)===1,'No additional provider calls on invalid requests');
// Test HTML metadata injection without revealing any key.
$html2='<html><body>'.$html.'</body></html>';
$render=KGPT_Translation::inject_manifest($html2);
assert_ok(str_contains($render,'id="kgpt-config"') && !str_contains($render,'unit-test-key') && count($transients)>=3,'Public manifest generated and API key omitted');
echo "SUCCESS: offline browser-language translation and Gemini contract checks.\n";

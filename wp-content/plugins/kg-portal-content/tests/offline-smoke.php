<?php
/** Unit checks with lightweight WordPress fakes, no DB or real source needed. */
define('ABSPATH','/tmp/');
function get_query_var($key){global $vars;return $vars[$key]??'';}
function get_option($name){return $name==='kgpc_schema_version'?'0.9.0':null;}
function current_user_can($cap){return $cap==='kgp_edit_entries';}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_strip_all_tags($x){return strip_tags($x);}
function sanitize_text_field($x){return strip_tags($x);}
function get_post_meta($id,$key,$single=true){global $metas;return $metas[$id][$key]??'';}
function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL)?$s:false;}
function wp_kses_post($s){return strip_tags($s,'<p><h2><h3><a><ul><li><strong><em>');}
function do_blocks($s){return $s;}
class WP_Post {public function __construct(public int $ID,public string $post_title,public string $post_content,public int $post_author=4,public string $post_type='kgpc_article') {}}
class KGP_Operator_Admin{public static function ready(){return true;}}
class KGP_DB{public static function require_row($table,$id){return ['public_url'=>'https://example.org/doc','document_title'=>'Sample official doc','checked_at'=>'2026-01-01'];}}
class KGP_Evidence_Repository{public static $valid=true;public static function valid_source($id){return self::$valid;}}
require dirname(__DIR__).'/includes/class-content.php';
require dirname(__DIR__).'/includes/class-review.php';
require dirname(__DIR__).'/includes/class-public.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException('FAIL: '.$msg);}
check(count(KGPC_Content::TOPICS)===6,'Six mandatory guide topics');
check(count(KGPC_Content::FAQ_CATS)===5,'FAQ categories');
check(count(KGPC_Content::POLICIES)===3,'Three policy documents');
$metas=[17=>['_kgpc_kind'=>'guide','_kgpc_lang'=>'ko','_kgpc_slug'=>'visa-preparation','_kgpc_topic'=>'visa-preparation','_kgpc_audience'=>'D-2 or D-4 students','_kgpc_basis_date'=>'','_kgpc_checked_at'=>'2026-11-12','_kgpc_source_ids'=>'3','_kgpc_source_locator'=>'p.4']];
$post=new WP_Post(17,'Visa preparation','<h2>Steps</h2><p>Official summary</p>');
$draft=KGPC_Review::candidate($post);
check($draft['lang']==='ko'&&$draft['source_ids']===[3],'Verified official draft passes');
$metas[17]['_kgpc_source_ids']='';
try{KGPC_Review::candidate($post);throw new RuntimeException('Unverified draft was allowed.');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'source'),'Missing sources blocked');}
$metas[17]['_kgpc_source_ids']='3';
KGP_Evidence_Repository::$valid=false;
try{KGPC_Review::candidate($post);throw new RuntimeException('Expired source was allowed.');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'source'),'Invalid source blocked');}
KGP_Evidence_Repository::$valid=true;
$public=['kind'=>'guide','lang'=>'ko','slug'=>'visa-preparation','title'=>'Korean','source_ids'=>[3]];
check(KGPC_Public::choose([$public],'guide','en','visa-preparation')['_fallback']===true,'Missing English approved content falls back to Korean');
$english=$public;$english['lang']='en';$english['title']='English';
check(KGPC_Public::choose([$public,$english],'guide','en','visa-preparation')['title']==='English','Approved English preferred');
check(KGPC_Content::sources_valid([3]),'Valid source check');
KGP_Evidence_Repository::$valid=false;
check(!KGPC_Content::sources_valid([3]),'Revoked evidence hidden at public-read gate');
$vars=['kgpc_view'=>'guide','kgpc_lang'=>'en'];
check(KGPC_Public::ours()&&KGPC_Public::lang()==='en','Localized guide route recognized');
check(!KGPC_Public::ours()===false,'Route gate check');
$vars=['kgpc_view'=>''];
check(!KGPC_Public::ours(),'Other routes remain untouched, including contact');
echo "PASS: six topics, FAQ, policy types, official-source publication gate, language fallback, evidence revocation, route isolation.\n";

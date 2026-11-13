<?php
/** Offline smoke; does not load WordPress, write DB or call network. */
define('ABSPATH','/tmp/');
$actions=[];$filters=[];$styles=[];$scripts=[];
function plugin_dir_path($file){return dirname($file).'/';}
function plugins_url($file,$reference){return '/plugins/kg-portal-design/'.$file;}
function add_action($name,$callback,$priority=10){global $actions;$actions[$name][]=['callback'=>$callback,'priority'=>$priority];}
function add_filter($name,$callback){global $filters;$filters[$name]=$callback;}
function wp_parse_url($url,$component=null){return parse_url($url,$component);}
function is_admin(){return $GLOBALS['is_admin']??false;}
function sanitize_key($str){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$str));}
function wp_unslash($str){return $str;}
function wp_enqueue_style($id,$uri,$deps=[],$version=''){global $styles;$styles[$id]=[$uri,$version];}
function wp_enqueue_script($id,$uri,$deps=[],$version='',$options=[]){global $scripts;$scripts[$id]=[$uri,$version,$options];}
function get_current_screen(){return $GLOBALS['screen']??null;}
function rest_url($x){return '/wp-json/'.$x;}
function wp_create_nonce($x){return 'test';}
function wp_json_encode($x){return json_encode($x);}
function wp_add_inline_script(...$args){}
require dirname(__DIR__).'/kg-portal-design.php';
function check($condition,$text){if(!$condition)throw new RuntimeException('FAIL: '.$text);echo "PASS: $text\n";}
check(KGDS_VERSION==='2.0.0','Version');
check(($actions['wp_enqueue_scripts'][0]['priority']??0)===999,'Public styles load after original template styles');
check(($actions['admin_enqueue_scripts'][0]['priority']??0)===999,'Admin styles load after original styles');
$_SERVER['REQUEST_URI']='/ko/guides/';$GLOBALS['is_admin']=false;
KGDS_Assets::public_assets();check(isset($styles['kgds-public'],$scripts['kgds-public']),'Guides get unified public assets');
$styles=[];$scripts=[];$_SERVER['REQUEST_URI']='/en/contact/';KGDS_Assets::public_assets();
check(isset($styles['kgds-public']),'Contact gets unified public assets');
$styles=[];$scripts=[];$_SERVER['REQUEST_URI']='/products/';KGDS_Assets::public_assets();
check(!$styles&&!$scripts,'Unrelated public routes untouched');
$GLOBALS['is_admin']=true;$GLOBALS['screen']=(object)['post_type'=>'kgpc_article'];
KGDS_Assets::admin_assets();check(isset($styles['kgds-admin'],$scripts['kgds-admin']),'Gutenberg content editor gets admin assets');
$styles=[];$scripts=[];$GLOBALS['screen']=(object)['post_type'=>'post'];$_GET['page']='kgpo-operations';
KGDS_Assets::admin_assets();check(isset($styles['kgds-admin']),'Source monitoring admin gets theme');
$styles=[];$scripts=[];$_GET['page']='nothing';KGDS_Assets::admin_assets();
check(!$styles,'Unrelated admin pages untouched');
$_GET['page']='kgpc-review';
check(str_contains(KGDS_Assets::admin_class('wp-admin'),'kgds-ui-admin'),'WordPress admin body receives scoped class');

$GLOBALS['is_admin']=false;
$_SERVER['REQUEST_URI']='/ko/admissions/';
check(in_array('kgds-route-admissions',KGDS_Assets::public_class(['base']),true),'Admissions gets its compact filter scope');
$_SERVER['REQUEST_URI']='/ko/guides/';
check(!in_array('kgds-route-admissions',KGDS_Assets::public_class(['base']),true),'Guide page unaffected by admission-specific rules');
$styles=[];$scripts=[];$_SERVER['REQUEST_URI']='/ko/universities/';
KGDS_Assets::public_assets();check(isset($scripts['kgds-filter-controls']),'Search filter enhancer loaded on university search');
check(in_array('kgds-filter-route',KGDS_Assets::public_class(['base']),true),'University search has scoped filter body class');
$styles=[];$scripts=[];$_SERVER['REQUEST_URI']='/ko/contact/';
KGDS_Assets::public_assets();check(!isset($scripts['kgds-filter-controls']),'Inquiry page does not load filter enhancer');

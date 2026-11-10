<?php
/** Pure-PHP unit verification. No WordPress instance or DB required. */
define('ABSPATH', '/');
define('KGP_FE_DIR', dirname(__DIR__));
function kgp_fe_lang():string{return 'ko';}
function esc_html($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($s):string{return esc_html($s);}
function esc_url($s,$protocols=[]):string{if (!is_string($s))return ''; $u=parse_url($s);return is_array($u)&&in_array(strtolower($u['scheme']??''),['http','https'],true)?$s:'';}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return strip_tags((string)$v);}
require_once KGP_FE_DIR.'/inc/view.php';
function assert_true($ok,$message){if(!$ok){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}}
assert_true(kgp_fe_t('nav.search','Find universities')==='대학 찾기','ko UI dictionary');
assert_true(kgp_fe_t('nav.search','Find universities','en')==='Find universities','en UI fallback');
assert_true(kgp_fe_safe_url('javascript:alert(1)')==='','javascript URL denied');
assert_true(kgp_fe_safe_url('https://university.example/guide.pdf')!=='','official https URL allowed');
$cost=kgp_fe_price(['min'=>4300000,'max'=>4700000,'unit'=>'semester','currency'=>'KRW']);
assert_true(str_contains($cost,'4,300,000')&&str_contains($cost,'4,700,000'),'official amount range with original unit');
assert_true(kgp_fe_price(['min'=>-100,'max'=>-50,'unit'=>'semester','currency'=>'KRW'])==='','negative amount hidden');
$q=kgp_fe_filter_params(['region'=>['seoul','busan','seoul'],'level'=>['bachelor'],'bad'=>'x']);
assert_true($q['region']==='seoul,busan'&&$q['level']==='bachelor'&&!isset($q['bad']),'safe OR normalization');
ob_start();kgp_fe_value(['note'=>'<script>alert(1)</script>','cost'=>['amount'=>'500','currency'=>'KRW','unit'=>'semester']]);$html=ob_get_clean();
assert_true(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'structured HTML escaping');
ob_start();kgp_fe_sources([['title'=>'Source','public_url'=>'javascript:alert(1)','checked_at'=>'2026-01-01']]);$src=ob_get_clean();
assert_true(!str_contains($src,'href="javascript:'),'invalid evidence link suppressed');
echo "PASS: localized labels, public URL validation, filters, money formatting, safe structured evidence output.\n";

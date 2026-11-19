<?php
/** Offline UI-only acceptance for BG/UI v1.2; zero database writes. */
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('KGP_FE_DIR',dirname(__DIR__));
function kgp_fe_lang(): string { return 'ko'; }
function kgp_fe_url(string $lang,string $path=''): string { return 'https://portal.example/'.($lang==='en'?'en':'ko').'/'.ltrim($path,'/'); }
function wp_unslash($v) { return $v; }
function sanitize_text_field(string $s): string {return trim(strip_tags($s));}
function esc_html($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($s): string {return esc_html($s);}
function esc_url($s,$allowed=null): string {return filter_var($s,FILTER_VALIDATE_URL)?$s:'';}
function add_query_arg(array $params,string $url): string {return $url.(count($params)?'?'.http_build_query($params):'');}
function passed(bool $condition,string $label):void{if(!$condition)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label."\n";}
require dirname(__DIR__).'/inc/view.php';
$raw=['q'=>'KAIST','region'=>['seoul','busan'],'field'=>'engineering,bad<>','level'=>'bachelor','sort'=>'unknown','certified'=>'0','tuition_min'=>'-1','page'=>'0'];
$ui=kgp_fe_ui_query($raw);$p=$ui['params'];
passed($ui['removed']===true,'Malformed shared URL values report a visible correction');
passed($p['q']==='KAIST' && $p['region']==='seoul,busan' && $p['level']==='bachelor','Query and legitimate multi-choice filters survive normalization');
passed(!isset($p['sort'],$p['page'],$p['tuition_min']),'Invalid sort, page and tuition inputs are discarded');
passed($p['certified']==='0','Negative certification remains a genuine filter, never silently changed to all');
$fee=kgp_fe_ui_query(['q'=>'Yonsei','cost_unit'=>'semester','tuition_min'=>'1000','tuition_max'=>'900']);
passed(!isset($fee['params']['tuition_min'],$fee['params']['tuition_max']) && $fee['removed'],'Inverted tuition range is rejected');
$noUnit=kgp_fe_ui_query(['q'=>'Yonsei','tuition_min'=>'5000']);
passed(!isset($noUnit['params']['tuition_min']),'Unitless tuition bound cannot be applied');
$ok=kgp_fe_ui_query(['q'=>'Seoul','level'=>'master','cost_unit'=>'semester','sort'=>'tuition']);
passed(($ok['params']['sort']??'')==='tuition','Tuition sort remains available with one degree level and explicit unit');
$bad=kgp_fe_ui_query(['q'=>'Seoul','level'=>'bachelor,master','cost_unit'=>'semester','sort'=>'tuition']);
passed(!isset($bad['params']['sort']),'Tuition sort with mixed levels is rejected');
ob_start();kgp_fe_applied_summary('ko','universities/',['q'=>'KAIST','region'=>'seoul,busan','certified'=>'0']);
$html=(string)ob_get_clean();
passed(str_contains($html,'적용된 검색 조건') && str_contains($html,'KAIST') && str_contains($html,'seoul') && str_contains($html,'busan'),'Applied filter chips render for multiple independent criteria');
passed(str_contains($html,'region=busan') && str_contains($html,'certified=0'),'Removing one chip preserves the other criteria');
passed(str_contains($html,'href="https://portal.example/ko/universities/"'),'Reset all clears keyword and all filter conditions');
$view=(string)file_get_contents(dirname(__DIR__).'/inc/public-template.php');
$shared=(string)file_get_contents(dirname(__DIR__).'/partials/kgp-header.php');
$script=(string)file_get_contents(dirname(__DIR__).'/assets/js/site.js');
passed(str_contains($shared,'class="kgp-menu"') && str_contains($shared,'kgp-header-switch')===false && !str_contains($shared,'kgp-brand-symbol'),'Text-only shared header uses one native menu and no unapproved logo mark');
passed(str_contains($view,'kgp-quick-links') && str_contains($view,'kgp_applied') && str_contains($view,'kgp_fe_ui_query'),'Quick exploration, Apply focus state and sanitized URL conditions present');
passed(str_contains($script,'kgp-results-heading') && str_contains($script,'kgp-admissions-heading'),'Results summary receives keyboard focus after Apply');
passed(strpos($view,'class="kgp-detail-toc"') < strpos($view,'class="kgp-section kgp-detail-next"'),'Detailed sections precede external action panel');
passed(str_contains($view,'<details class="kgp-detail-toc"') && str_contains($view,'kgp-detail-section'),'Native TOC and independently expandable section disclosures preserved');
echo 'SUCCESS: v1.2 UI query, reset semantics, shared menu, and detail structure. No DB access.\n';

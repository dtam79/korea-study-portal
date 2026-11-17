<?php
/** Offline acceptance for P02/P04 published-only display and P03/P08 navigational contract. */
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('KGP_FE_DIR',dirname(__DIR__));
function kgp_fe_lang(): string {return 'ko';}
function kgp_fe_url(string $lang,string $path=''): string {return 'https://portal.test/'.($lang==='en'?'en':'ko').'/'.ltrim($path,'/');}
function esc_html($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($s): string {return esc_html($s);}
function esc_url($s,$schemes=null): string {return filter_var($s,FILTER_VALIDATE_URL)?$s:'';}
function add_query_arg(array $args,string $url): string {return $url.'?'.http_build_query($args);}
require dirname(__DIR__).'/inc/view.php';
function verify(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$time=['status'=>'open','start_date'=>'2027-08-01','end_date'=>'2027-09-01','end_precision'=>'minute',
    'ends_at_utc'=>'2027-09-01 06:30:00','timezone'=>'Asia/Seoul'];
verify(kgp_fe_official_admission_date($time,'end')==='2027-09-01 15:30 (Asia/Seoul)',
    'Exact official UTC deadline displayed in its declared timezone.');
verify(!kgp_fe_admission_time_unpublished($time,'end'),'No misleading time-unpublished badge on exact deadline.');
$dateonly=['end_date'=>'2027-09-01','end_precision'=>'day','timezone'=>'Asia/Seoul'];
verify(kgp_fe_official_admission_date($dateonly,'end')==='2027-09-01',
    'Date-only deadline is never turned into 23:59.');
verify(kgp_fe_admission_time_unpublished($dateonly,'end'),'Date-only time is explicitly unverified.');
$invalid=$time;$invalid['timezone']='Mars/Unknown';
verify(kgp_fe_official_admission_date($invalid,'end')==='2027-09-01',
    'Unknown timezone cannot produce a manufactured exact deadline.');
verify(kgp_fe_teaching_languages(['ko','en','en','invalid<script>'])==='한국어, 영어',
    'Only valid approved teaching language codes are displayed and duplicates suppressed.');
$card=['offering_id'=>'off2027','university_id'=>'uni2027','university_name'=>'한국대학교',
    'program_name'=>'컴퓨터과학','campus_name'=>'서울캠퍼스','campus_region'=>'seoul','level'=>'bachelor',
    'academic_year'=>2027,'academic_term'=>'fall','teaching_languages'=>['ko','en'],
    'tuition'=>[['currency'=>'KRW','unit'=>'semester','min'=>3000000,'max'=>3000000]],
    'certified'=>null,'admission'=>$time];
ob_start();kgp_fe_card($card,'ko');$html=(string)ob_get_clean();
foreach (['수업 언어','한국어, 영어','모집 학년도·학기','2027 fall','2027-09-01 15:30 (Asia/Seoul)'] as $piece) {
    verify(str_contains($html,$piece),'Card displays approved API field: '.$piece);
}
verify(!str_contains($html,'공식 마감 시각 확인 필요'),'Precise deadline card omits check-time fallback.');
$card['admission']=$dateonly;ob_start();kgp_fe_card($card,'ko');$html=(string)ob_get_clean();
verify(str_contains($html,'공식 마감 시각 확인 필요'),'Date-only deadline card labels unavailable time.');
$detail=(string)file_get_contents(dirname(__DIR__).'/inc/public-template.php');
$contact=(string)file_get_contents(dirname(__DIR__).'/../../plugins/kg-portal-admin/templates/contact.php');
verify(str_contains($detail,'kgp-detail-toc') && str_contains($detail,'kgp-det-'),
    'Published detail sections have same-section table of contents.');
verify(str_contains($detail,"'offering'=>\$selected") && str_contains($contact,'$allowed_offerings'),
    'Detail-to-contact preserves and validates offering selection without exposing private IDs.');
verify(str_contains($detail,"'type'=>'university'"),'University inquiries are marked as external-route contact.');
echo "SUCCESS: P02/P03/P04/P08 display contract. No WordPress or database writes.\n";

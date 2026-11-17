<?php
/** Offline, source-gated P02/P03 contract. No WordPress or database writes. */
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('KGP_FE_DIR',dirname(__DIR__,3).'/themes/kg-portal-child');
function require_true(bool $ok,string $name): void {
    if (!$ok) {fwrite(STDERR,"FAIL: $name\n");exit(1);}
    echo "PASS: $name\n";
}
class FakeWpdb {
    public function prepare(string $sql,...$args): string {
        return $sql.' /*'.implode(',',array_map('strval',$args)).'*/';
    }
}
$wpdb=new FakeWpdb();
class KGP_DB {
    public static array $sources=[];
    public static array $issuers=[];
    public static array $identities=[];
    public static array $links=[];
    public static function table(string $t): string {return 'test_'.$t;}
    public static function require_row(string $table,int $id): array {
        $rows=match($table){'sources'=>self::$sources,'issuers'=>self::$issuers,default=>[]};
        if (!isset($rows[$id])) throw new RuntimeException('Missing fixture '.$table.$id);
        return $rows[$id];
    }
    public static function all(string $sql): array {return self::$links;}
    public static function one(string $sql): ?array {return self::$identities[1]??null;}
}
require dirname(__DIR__).'/src/Repository/EvidenceRepository.php';
require dirname(__DIR__).'/src/Repository/IdentityRepository.php';
require dirname(__DIR__).'/src/Service/PublicCatalog.php';
KGP_DB::$issuers=[1=>['id'=>1,'official_name'=>'Official University','approval_status'=>'approved']];
$source=['id'=>1,'issuer_id'=>1,'document_title'=>'Official Admissions Guide',
    'public_url'=>'https://university.example.edu/official', 'document_locator'=>'section 2',
    'applicable_to'=>'bachelor intake 2027','valid_from'=>null,'valid_to'=>null,
    'checked_at'=>'2026-10-12 12:45:00','evidence_status'=>'verified',
    'verified_by'=>3,'verified_at'=>'2026-10-12 12:45:00'];
KGP_DB::$sources=[1=>$source,2=>array_replace($source,['id'=>2,'checked_at'=>'2026-11-04 12:00:00'])];
KGP_DB::$identities=[1=>['published_json'=>json_encode(['official_name_en'=>'Verified Name','public_id'=>'univ2027']),
    'published_source_id'=>1,'published_source_locator'=>'PDF page 4']];
KGP_DB::$links=[['source_id'=>2,'original_locator'=>'table 1','applies_to'=>'foreign bachelor intake',
    'valid_from'=>null,'valid_to'=>null]];
$identity=KGP_Identity_Repository::published('university',1);
require_true(($identity['identity_evidence']['publisher']??'')==='Official University', 'Only verified identity exposes official publisher');
require_true(($identity['identity_evidence']['applicability']??'')==='bachelor intake 2027', 'Identity retains scope and source applicability');
require_true(($identity['identity_evidence']['locator']??'')==='PDF page 4', 'Reviewer-approved locator wins over generic source locator');
$evidence=KGP_Evidence_Repository::verified_sources_for_version(9);
require_true(count($evidence)===1 && $evidence[0]['publisher']==='Official University', 'Approved field evidence includes original publisher');
require_true($evidence[0]['document_locator']==='table 1' && $evidence[0]['applicability']==='foreign bachelor intake', 'Repeat item-specific source locator and scope retained');
$identitySources=array_fill(0,5,$identity);
$fields=[['source_documents'=>$evidence]];
$method=(new ReflectionClass(KGP_Public_Catalog::class))->getMethod('card_source_check_date');
require_true($method->invoke(null,$identitySources,$fields)==='2026-10-12', 'Oldest official check date across card identities and fields');
$latest=$identitySources;$latest[0]['identity_evidence']['checked_at']='2026-11-17 15:01:00';
require_true($method->invoke(null,$latest,$fields)==='2026-10-12', 'Recent check on one item cannot mask older checks on others');
$missing=$identitySources;$missing[2]['identity_evidence']['checked_at']=null;
require_true($method->invoke(null,$missing,$fields)===null, 'Missing approved check date cannot become an invented date');
$malformed=$identitySources;$malformed[2]['identity_evidence']['checked_at']='2026-02-31 00:00:00';
require_true($method->invoke(null,$malformed,$fields)===null, 'Invalid calendar date rejected');
require_true($method->invoke(null,$identitySources,[['source_documents'=>[]]])===null, 'Incomplete field-source linkage never receives check badge');
KGP_DB::$sources[1]['evidence_status']='invalid';
require_true(KGP_Identity_Repository::published('university',1)===null, 'Invalidated source cannot publish identity or metadata');
KGP_DB::$sources[2]['evidence_status']='invalid';
require_true(KGP_Evidence_Repository::verified_sources_for_version(9)===[], 'Invalidated field evidence not exposed');
// Restore fixtures and exercise user-facing escaped output.
KGP_DB::$sources[1]=$source;
function kgp_fe_lang(): string{return 'ko';}
function kgp_fe_url(string $lang,string $path=''): string{return 'https://portal.example/'.$lang.'/'.ltrim($path,'/');}
function esc_html($v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($v): string{return esc_html($v);}
function esc_url($v,$protocols=null): string{return (string)$v;}
function add_query_arg(array $args,string $url): string{return $url.'?'.http_build_query($args);}
require dirname(__DIR__,3).'/themes/kg-portal-child/inc/view.php';
$card=['offering_id'=>'offer2027','university_id'=>'univ2027','university_name'=>'Official University',
    'campus_name'=>'Main','campus_region'=>'seoul','program_name'=>'Engineering','level'=>'bachelor',
    'academic_year'=>2027,'academic_term'=>'fall','teaching_languages'=>['en'],'tuition'=>[],
    'admission'=>['status'=>'upcoming'],'card_source_checked_at'=>'2026-10-12'];
ob_start();kgp_fe_card($card,'ko');$html=(string)ob_get_clean();
require_true(str_contains($html,'2026-10-12') && str_contains($html,'공식 자료 확인일'), 'P02/P04 cards show the approved conservative source-check date');
$card['card_source_checked_at']=null;
ob_start();kgp_fe_card($card,'ko');$missingHtml=(string)ob_get_clean();
require_true(!str_contains($missingHtml,'공식 자료 확인일'), 'Unknown dates are hidden instead of fabricated');
ob_start();kgp_fe_sources([['publisher'=>'Official University','title'=>'Guide <verified>',
    'public_url'=>'https://university.example.edu/guide','document_locator'=>'p.7',
    'checked_at'=>'2026-10-12','applicability'=>'2027 admission']]);
$sourceHtml=(string)ob_get_clean();
require_true(str_contains($sourceHtml,'발행기관: Official University') && str_contains($sourceHtml,'&lt;verified&gt;') && !str_contains($sourceHtml,'Guide <verified>'),
    'P03 C06 source disclosure shows publisher and escapes all evidence text');
echo "SUCCESS: Published-only evidence and P02/P03 source-date contract. No database changes.\n";

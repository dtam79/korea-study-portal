<?php
/** Public source disclosure contract: no WordPress DB writes. */
declare(strict_types=1);
define('ABSPATH','/tmp/');
function esc_html($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_url($s,$schemes=null):string{return filter_var($s,FILTER_VALIDATE_URL)?(string)$s:'';}
class KGP_DB {
    public static function require_row(string $table,int $id):array{
        if($table==='sources')return [
            'issuer_id'=>2,'public_url'=>'https://university.example/2026-admissions.pdf',
            'document_title'=>'International Admissions 2026',
            'checked_at'=>'2026-11-16 10:00:00','document_locator'=>'page 8',
            'applicable_to'=>'Fall 2026 undergraduate applicants',
        ];
        if($table==='issuers')return ['official_name'=>'Sample University Office of Admissions'];
        throw new RuntimeException('Unexpected table');
    }
}
class KGP_Evidence_Repository {public static bool $valid=true;public static function valid_source(int $id):bool {return self::$valid;}}
require dirname(__DIR__).'/includes/class-public.php';
$src=['source_ids'=>[11],'source_locator'=>'Section 3 / page 8'];
$html=KGPC_Public::sources($src,'en');
foreach(['<details class="kgpc-sources">','<summary>','International Admissions 2026','Sample University Office of Admissions',
        'https://university.example/2026-admissions.pdf','Fall 2026 undergraduate applicants','Section 3 / page 8','2026-11-16'] as $token){
    if(!str_contains($html,$token))throw new RuntimeException('Missing disclosure field: '.$token);
}
KGP_Evidence_Repository::$valid=false;
if(KGPC_Public::sources($src,'en')!=='')throw new RuntimeException('Revoked official evidence must not render as a public link');
print "PASS: source title, publisher, original, applicability, locator, checked date; revoked evidence hidden.\n";

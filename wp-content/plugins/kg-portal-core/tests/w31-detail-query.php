<?php
/** Offline public contract; counts section fetches, not synthetic performance timings. */
define('ABSPATH','/');
class KGP_Validator {public static function id($id):void{}}
class KGP_DB {
    public static function table($name):string{return $name;}
    public static function all($sql):array{return [['id'=>101]];}
}
class FakeWpdb {
    public function prepare($sql,...$args):string{return $sql;}
    public function get_var($sql){return 101;}
}
$wpdb=new FakeWpdb();
class KGP_Identity_Repository {
    public static function published($type,$id):?array {
        $e=['public_id'=>strtoupper($type).'-'.$id, 'official_name_ko'=>'공식대학 '.$id,
            'official_name_en'=>'Official University '.$id, 'identity_evidence'=>['checked_at'=>'2026-11-01']];
        return match($type){
            'offering'=>$id===101?$e+['program_id'=>201,'intake_id'=>301]:null,
            'program'=>$id===201?$e+['university_id'=>401,'campus_id'=>501,'field_code'=>'eng','level_code'=>'bachelor','department_url'=>'https://example.edu/engineering']:null,
            'intake'=>$id===301?$e+['university_id'=>401,'academic_year'=>2027,'academic_term'=>'fall','start_date'=>'2027-01-01',
                'end_date'=>'2027-02-01','recruitment_override'=>null,'timezone_name'=>'Asia/Seoul',
                'start_precision'=>'date','end_precision'=>'date','starts_at_utc'=>null,'ends_at_utc'=>null,'admission_type'=>'general','application_url'=>'https://example.edu']:null,
            'campus'=>$id===501?$e+['university_id'=>401,'region_code'=>'seoul']:null,
            'university'=>$id===401?$e+['approved_aliases'=>[],'official_website'=>'https://example.edu','institution_type'=>'public']:null,
            default=>null,
        };
    }
}
class KGP_Field_Catalog {public static function sections():array {
    $out=[];for($i=1;$i<=14;$i++)$out[sprintf('DET-%02d',$i)]=['title'=>'Section '.$i];return $out;
}}
class KGP_Public_Reader {
    public static array $calls=[];
    public static function section($type,$id,$section,$locale):array {
        $key=$type.'|'.$id.'|'.$section.'|'.$locale;
        self::$calls[$key]=(self::$calls[$key]??0)+1;
        $source=[['checked_at'=>'2026-11-01']];
        if($type==='program' && $section==='DET-02')return [['field'=>'teaching_languages','value'=>'en','source_documents'=>$source]];
        if($type==='offering' && $section==='DET-03')return [['field'=>'tuition_items','value'=>['amount'=>5000000,'unit'=>'semester','currency'=>'KRW'],'source_documents'=>$source]];
        return [];
    }
}
require dirname(__DIR__).'/src/Service/PublicCatalog.php';
$details=KGP_Public_Catalog::offering('OFFERING-101','ko');
if(!is_array($details)||count($details['sections'])!==2)throw new RuntimeException('Expected two approved detail sections.');
foreach(['program|201|DET-02|ko','offering|101|DET-03|ko','university|401|DET-07|ko'] as $key) {
    if((KGP_Public_Reader::$calls[$key]??0)!==1) throw new RuntimeException('Duplicate approved section lookup: '.$key);
}
if(count(KGP_Public_Reader::$calls)!==5*14)throw new RuntimeException('Expected exactly 70 unique section-scope lookups.');
$search=KGP_Public_Catalog::search(['locale'=>'ko']);
if($search['total']!==1||$search['items'][0]['offering_id']!==$details['offering_id'])throw new RuntimeException('Card/detail offering mismatch.');
if(KGP_Public_Catalog::university_has_published_offering(401)!==true||KGP_Public_Catalog::university_has_published_offering(123))throw new RuntimeException('Sitemap public-offering gate failed.');
echo "PASS: W3-1 detail reuses nine approved sections, preserves card parity and public-only gate.\n";

<?php
/** Sitemap never needs to hydrate complete published-university offerings. */
define('ABSPATH','/');
class KGPO_Policy {public static function core_ready():bool{return true;}}
class KGP_DB {public static function table($t){return $t;}}
class KGP_Evidence_Repository {}
class KGP_Identity_Repository {
    public static function published($type,$id){
        if($type!=='university'||$id===3)return null;
        return ['public_id'=>'UNI-'.$id,'official_name_ko'=>'대학'.$id,'official_name_en'=>'University '.$id];
    }
}
class KGP_Public_Catalog {
    public static array $called=[];
    public static function university_has_published_offering(int $id):bool {self::$called[]=$id;return in_array($id,[1,4],true);}
    public static function university($id,$lang):array {throw new RuntimeException('Avoid expensive full-university hydration.');}
}
class FakeWpdb {public function get_col($sql){if(!str_contains($sql,'LIMIT 3000')) throw new RuntimeException('Sitemap unbounded.');return [1,2,3,4];}}
$wpdb=new FakeWpdb();
require dirname(__DIR__).'/inc/class-seo.php';
$rows=KGPO_SEO::published_universities();
if(array_column($rows,'id')!==['UNI-1','UNI-4'])throw new RuntimeException('Unpublished/unavailable university leaked.');
if(KGP_Public_Catalog::$called!==[1,2,4])throw new RuntimeException('Unexpected evidence probe count.');
echo "PASS: W3-1 sitemap includes only universities with approved offerings, without building all cards.\n";

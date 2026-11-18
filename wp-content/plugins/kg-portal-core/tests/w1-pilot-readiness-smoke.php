<?php
/** Isolated tests for W1-2 read-only two-university readiness semantics. */
declare(strict_types=1);
define('ABSPATH','/tmp/wordpress/');
class KGP_Validator {
    public static function id(string $id): string {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/D', $id)) throw new InvalidArgumentException('Invalid ID');
        return $id;
    }
}
class FakeWPDB {
    public function prepare(string $sql, string $id): string { return str_replace('%s',"'".$id."'",$sql); }
}
$wpdb = new FakeWPDB();
class KGP_DB {
    public static function table(string $name): string { return 'wp_kgp_'.$name; }
    public static function one(string $sql): ?array {
        if (str_contains($sql,"'UNI-A'")) return ['id'=>1];
        if (str_contains($sql,"'UNI-B'")) return ['id'=>2];
        return null;
    }
}
class KGP_Identity_Repository {
    public static bool $revokeA = false;
    public static function published(string $type, int $id): ?array {
        if ($id === 1 && self::$revokeA) return null;
        return ['official_name_en'=>$id===1?'University A':'University B'];
    }
}
class KGP_Public_Catalog {
    public static bool $missingEnglish = false;
    public static bool $crossUniversity = false;
    public static bool $missingSource = false;
    public static bool $missingDate = false;
    public static function university(string $id, string $locale): array {
        $offering = $id === 'UNI-A' ? 'OFF-A' : 'OFF-B';
        if ($locale==='en' && self::$missingEnglish && $id==='UNI-A') return ['offerings'=>[]];
        return ['offerings'=>[['offering_id'=>$offering,
            'university_id'=>self::$crossUniversity&&$id==='UNI-A'?'UNI-B':$id,
            'card_source_checked_at'=>self::$missingDate?null:'2026-11-18']]];
    }
    public static function offering(string $offering, string $locale): array {
        $uni=$offering==='OFF-A'?'UNI-A':'UNI-B';
        return ['offering_id'=>$offering,'university_id'=>$uni,'academic_year'=>2027,
            'academic_term'=>'fall','teaching_languages'=>['en'],'english_only'=>null,
            'tuition'=>[['min'=>4100000.0,'max'=>4100000.0,'unit'=>'semester','currency'=>'KRW']],
            'certified'=>null, 'admission'=>['status'=>'unknown'], 'card_source_checked_at'=>
                self::$missingDate?null:'2026-11-18',
            'sections'=>[['groups'=>[['fields'=>[['source_documents'=>self::$missingSource?[]:[[
                'publisher'=>'Agency','public_url'=>'https://example.org/a.pdf','checked_at'=>'2026-11-18']]]]]]]]];
    }
}
require_once __DIR__ . '/../src/Service/PilotReadiness.php';
function expect_w12(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message."\n";
}
function must_reject_w12(array $ids): void {
    try { KGP_Pilot_Readiness::check($ids); }
    catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid pilot IDs accepted');
}
must_reject_w12(['UNI-A']);
must_reject_w12(['UNI-A','UNI-A']);
must_reject_w12(['UNI-A','  ']);
expect_w12(KGP_Pilot_Readiness::check(['UNI-A','UNI-B'])['technically_ready'],
    'two eligible independent universities pass');
KGP_Identity_Repository::$revokeA = true;
$r = KGP_Pilot_Readiness::check(['UNI-A','UNI-B']);
expect_w12(!$r['technically_ready'] && count($r['universities'][0]['issues'])>0 && !$r['universities'][1]['issues'],
    'revoked source blocks only the affected university');
KGP_Identity_Repository::$revokeA = false;
KGP_Public_Catalog::$missingEnglish = true;
expect_w12(!KGP_Pilot_Readiness::check(['UNI-A','UNI-B'])['technically_ready'], 'KO/EN missing offering detected');
KGP_Public_Catalog::$missingEnglish = false;
KGP_Public_Catalog::$crossUniversity = true;
expect_w12(!KGP_Pilot_Readiness::check(['UNI-A','UNI-B'])['technically_ready'], 'cross-university offering detected');
KGP_Public_Catalog::$crossUniversity = false;
KGP_Public_Catalog::$missingSource = true;
expect_w12(!KGP_Pilot_Readiness::check(['UNI-A','UNI-B'])['technically_ready'], 'source provenance required');
KGP_Public_Catalog::$missingSource = false;
KGP_Public_Catalog::$missingDate = true;
expect_w12(!KGP_Pilot_Readiness::check(['UNI-A','UNI-B'])['technically_ready'], 'unknown check date stays warning, not invented');
KGP_Public_Catalog::$missingDate = false;
expect_w12(!KGP_Pilot_Readiness::check(['UNI-A','DOES-NOT-EXIST'])['technically_ready'], 'missing real university fails closed');
echo "SUCCESS: W1-2 readiness smoke passed without any DB writes.\n";

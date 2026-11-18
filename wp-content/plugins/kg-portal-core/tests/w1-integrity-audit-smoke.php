<?php
/** W1-1 isolated source-contract + simulated read-only audit scenarios. */
define('ABSPATH', '/tmp/wordpress/');
function wp_parse_url($url) { return parse_url($url); }
require_once __DIR__ . '/../src/Support/DomainException.php';
require_once __DIR__ . '/../src/Database/FieldCatalog.php';
require_once __DIR__ . '/../src/Validation/Validator.php';

function expect_w1(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('W1 regression failed: ' . $label);
}
function must_reject_w1(array $value, string $label): void {
    try {
        KGP_Validator::field('DET-03','tuition_items',$value,'fee_01');
    } catch (KGP_Domain_Exception $e) {
        return;
    }
    throw new RuntimeException('W1 validation accepted invalid ' . $label);
}
$money = ['currency'=>'KRW','unit'=>'semester'];
must_reject_w1($money + ['amount'=>'10','min_amount'=>'9'], 'amount plus incomplete range');
must_reject_w1($money + ['amount'=>'10','min_amount'=>'9','max_amount'=>'12'], 'amount plus complete range');
must_reject_w1($money + ['min_amount'=>'5'], 'range missing max');
must_reject_w1($money + ['max_amount'=>'5'], 'range missing min');
must_reject_w1($money, 'missing amount');
must_reject_w1($money + ['min_amount'=>'20','max_amount'=>'10'], 'reversed range');
$zero = KGP_Validator::field('DET-03','tuition_items',$money + ['amount'=>'0'],'zero');
expect_w1($zero['value']['amount'] === '0', 'actual approved zero preserved');
$range = KGP_Validator::field('DET-03','tuition_items',$money + ['min_amount'=>'5000','max_amount'=>'9000'],'fees');
expect_w1($range['value']['max_amount'] === '9000', 'valid official range preserved');
expect_w1(KGP_Validator::assert_catalog_covered() === ['sections'=>14,'fields'=>84], 'complete DET catalog');

// Controlled simulated DB responses: no actual WordPress DB or writes.
class KGP_Schema {
    public static function status(): array { return ['ok'=>true]; }
    public static function names(): array {
        return array_combine(
            ['universities','campuses','programs','intakes','offerings','field_entries','sources','field_versions','entity_publications'],
            array_map(fn($v) => 'wp_kgp_' . $v,
                ['universities','campuses','programs','intakes','offerings','field_entries','sources','field_versions','entity_publications'])
        );
    }
}
class KGP_DB {
    public static bool $broken_parent = false;
    public static bool $bad_source = false;
    public static bool $bad_field = false;
    public static bool $oversized = false;
    public static function one(string $sql): array {
        if (str_contains($sql, 'FROM wp_kgp_programs p LEFT JOIN')) {
            return ['quantity' => self::$broken_parent ? 1 : 0];
        }
        if (str_contains($sql, 'FROM wp_kgp_offerings') && !str_contains($sql, ' o LEFT JOIN')) {
            return ['quantity'=>1];
        }
        if (str_contains($sql, 'FROM wp_kgp_field_entries WHERE published_version_id')) {
            return ['quantity'=>1];
        }
        if (str_contains($sql, 'FROM wp_kgp_entity_publications WHERE published_json')) {
            return ['quantity'=>1];
        }
        return ['quantity'=>0];
    }
    public static function all(string $sql): array {
        if (str_contains($sql, 'SELECT e.id AS entry_id')) {
            $data = ['currency'=>'KRW','unit'=>'semester','amount'=>'0'];
            if (self::$bad_field) $data['min_amount'] = '1';
            $item = ['entry_id'=>7,'section_code'=>'DET-03','field_key'=>'tuition_items','item_key'=>'fee_01',
                'version_id'=>8,'value_type'=>'repeat:money_item','value_json'=>json_encode($data)];
            return self::$oversized ? [$item,$item] : [$item];
        }
        if (str_contains($sql, 'SELECT id, entity_type, entity_id, published_source_id')) {
            return [['id'=>4,'entity_type'=>'university','entity_id'=>1,'published_source_id'=>9]];
        }
        return [];
    }
}
class KGP_Evidence_Repository {
    public static function verified_sources_for_version(int $version): array { return KGP_DB::$bad_source ? [] : [['source_id'=>9]]; }
    public static function valid_source(int $source): bool { return !KGP_DB::$bad_source && $source===9; }
}
require_once __DIR__ . '/../src/Service/IntegrityAudit.php';
$clean = KGP_Integrity_Audit::scan();
expect_w1(count($clean['issues'])===0, 'clean read-only audit');
expect_w1($clean['counts']['det_fields']===84 && $clean['counts']['published_fields']===1, 'published coverage counters');
KGP_DB::$broken_parent = true;
$broken = KGP_Integrity_Audit::scan();
expect_w1(in_array('invalid_program_parent',array_column($broken['issues'],'code'),true), 'cross-university parent detected');
KGP_DB::$broken_parent = false;
KGP_DB::$bad_field = true;
$invalid = KGP_Integrity_Audit::scan();
expect_w1(in_array('invalid_published_value',array_column($invalid['issues'],'code'),true), 'mixed money form detected');
KGP_DB::$bad_field = false;
KGP_DB::$bad_source = true;
$invalid = KGP_Integrity_Audit::scan();
expect_w1(in_array('invalid_published_evidence',array_column($invalid['issues'],'code'),true), 'expired evidence flagged');
expect_w1(in_array('invalid_published_identity_source',array_column($invalid['issues'],'code'),true), 'identity evidence flagged');
KGP_DB::$bad_source = false;
KGP_DB::$oversized = true;
$invalid = KGP_Integrity_Audit::scan(1);
expect_w1(in_array('audit_limit_reached',array_column($invalid['issues'],'code'),true), 'partial scan cannot pass');
expect_w1(KGP_DB::one('SELECT COUNT(*) AS quantity FROM wp_kgp_universities')['quantity'] === 0, 'read-only fake DB');
fwrite(STDOUT, "PASS: W1-1 official fee, 84-field coverage, broken parents, source validity, fail-closed scan\n");

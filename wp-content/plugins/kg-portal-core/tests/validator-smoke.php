<?php
/** Standalone tests without WordPress, database or networking. */
define('ABSPATH', '/tmp/wordpress/');
function wp_parse_url($url) { return parse_url($url); }
require_once __DIR__ . '/../src/Support/DomainException.php';
require_once __DIR__ . '/../src/Database/FieldCatalog.php';
require_once __DIR__ . '/../src/Validation/Validator.php';

function must(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $cb, string $message): void {
    try { $cb(); } catch (KGP_Domain_Exception $e) { return; }
    throw new RuntimeException('Rejection missing: ' . $message);
}
function sample(string $type) {
    if (str_starts_with($type, 'repeat:')) return sample(substr($type, 7));
    if (str_starts_with($type, 'enum:')) return explode('|', substr($type, 5))[0];
    return match($type) {
        'localized_text' => 'Official guidance, example only',
        'url' => 'https://example.org/study',
        'year' => 2027,
        'nullable_boolean' => false,
        'locale_code' => 'ko',
        'money_item' => ['currency'=>'KRW','unit'=>'semester','amount'=>'0'],
        'date_with_precision' => ['date'=>'2027-02','precision'=>'month'],
        'duration' => ['value'=>8,'unit'=>'week'],
        'structured_schedule' => ['start'=>['date'=>'2027-02-01','precision'=>'day']],
        'document' => ['name'=>'Transcript'],
        'document_authentication' => ['method'=>'Apostille'],
        'language_requirement' => ['test'=>'TOPIK','score'=>'3'],
        'waiver' => ['condition'=>'Example public condition'],
        'official_major' => ['name'=>'Computer science'],
        'scholarship' => ['name'=>'Example scholarship','benefit'=>'Official benefit','period'=>'first_semester'],
        'official_evaluation' => ['organization'=>'Institution','year'=>2027],
        'certification' => ['name'=>'Example certification','course_type'=>'degree'],
        'restriction' => ['name'=>'Example restriction','course_type'=>'language'],
        'official_address' => ['address'=>'Example campus'],
        'official_guidance' => ['title'=>'Example official guidance'],
        'official_contact' => ['department'=>'International office'],
        'official_program' => ['name'=>'Orientation'],
        'official_case' => ['title'=>'Official example case'],
        'published_statistic' => ['reference_date'=>['date'=>'2027-04-01','precision'=>'day'],'count'=>0],
        default => throw new RuntimeException('Test lacks type: ' . $type),
    };
}
$coverage = KGP_Validator::assert_catalog_covered();
$count = 0;
foreach (KGP_Field_Catalog::sections() as $section => $info) {
    foreach ($info['fields'] as $key => $type) {
        $item = str_starts_with($type, 'repeat:') ? 'item_01' : '';
        $expected = sample($type);
        $checked = KGP_Validator::field($section, $key, $expected, $item);
        must($checked['type'] === $type, 'Field type mismatch ' . $key);
        ++$count;
    }
}
must($coverage['sections'] === 14 && $coverage['fields'] === $count && $count === 84, 'Catalog count mismatch.');
rejected(fn()=>KGP_Validator::field('DET-03','tuition_items',['currency'=>'KRW','unit'=>'semester','amount'=>'-1'],'item_01'), 'negative money');
rejected(fn()=>KGP_Validator::field('DET-03','tuition_items',['currency'=>'USD','unit'=>'semester','amount'=>'12'],'item_01'), 'foreign currency');
rejected(fn()=>KGP_Validator::field('DET-03','tuition_items',['currency'=>'KRW','unit'=>'semester','amount'=>'12']), 'missing repeat key');
rejected(fn()=>KGP_Validator::field('DET-02','english_only_program',1), 'numeric boolean');
rejected(fn()=>KGP_Validator::field('DET-01','official_application_url','javascript:alert(1)'), 'javascript URL');
rejected(fn()=>KGP_Validator::field('DET-01','eligibility','<script>alert(1)</script>'), 'HTML injection');
rejected(fn()=>KGP_Validator::field('DET-01','application_schedule',['start'=>['date'=>'2027-04-01','precision'=>'day'],'end'=>['date'=>'2027-03-01','precision'=>'day']]), 'reverse date');
rejected(fn()=>KGP_Validator::field('DET-12','disclosure_reference_date',['date'=>'2027-02-30','precision'=>'day']), 'invalid date');
must(KGP_Validator::field('DET-03','gks_host_institution',false)['value'] === false, 'Verified false lost');
must(KGP_Validator::field('DET-03','tuition_items',['currency'=>'KRW','unit'=>'semester','amount'=>'0'],'item_02')['value']['amount'] === '0', 'Actual zero lost');
fwrite(STDOUT, "PASS: {$coverage['sections']} sections, {$count} fields validated; 10 edge cases passed.\n");

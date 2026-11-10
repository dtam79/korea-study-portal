<?php
/**
 * Local shape test; requires only PHP CLI, no WordPress or database.
 * Run: php tests/schema-shape-smoke.php
 */
define('ABSPATH', '/tmp/wordpress/');
class KGP_Fake_DB {
    public string $prefix = 'wp_';
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
}
$wpdb = new KGP_Fake_DB();
require_once __DIR__ . '/../src/Database/Schema.php';
require_once __DIR__ . '/../src/Database/FieldCatalog.php';

function ensure(bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$tables = KGP_Schema::names();
$sql = KGP_Schema::sql();
$sections = KGP_Field_Catalog::sections();

ensure(count($tables) === 20, 'Expected 20 KGP tables');
ensure(count($sql) === count($tables), 'SQL/table list does not match');
ensure(count($sections) === 14, 'All 14 DET sections are required');
for ($i = 1; $i <= 14; ++$i) {
    $code = sprintf('DET-%02d', $i);
    ensure(isset($sections[$code]), 'Missing ' . $code);
    ensure(count($sections[$code]['fields']) > 0, 'No fields in ' . $code);
}
foreach ($sql as $query) {
    ensure(str_starts_with($query, 'CREATE TABLE wp_kgp_'), 'Invalid table declaration');
    ensure(str_contains($query, 'PRIMARY KEY  ('), 'dbDelta primary key format mismatch');
    ensure(str_contains($query, 'DEFAULT CHARACTER SET utf8mb4'), 'Missing character set');
    ensure(!str_contains($query, 'FOREIGN KEY'), 'dbDelta must not receive FK definitions');
    ensure(!preg_match('/\brow_number\s+int\b/i', $query), 'Do not use reserved ROW_NUMBER as an unquoted column');
}
$must = ['field_entries', 'field_versions', 'field_version_sources', 'translations', 'offering_search', 'inquiries', 'audit_events', 'entity_publications'];
foreach ($must as $key) {
    ensure(isset($tables[$key]), 'Missing structure: ' . $key);
}
ensure(KGP_Field_Catalog::has_field('DET-03', 'tuition_items'));
ensure(KGP_Field_Catalog::has_field('DET-12', 'student_counts_by_nationality'));
fwrite(STDOUT, sprintf("PASS: %d tables, %d sections, %d field definitions\n", count($tables), count($sections), array_sum(array_map(fn($x) => count($x['fields']), $sections))));

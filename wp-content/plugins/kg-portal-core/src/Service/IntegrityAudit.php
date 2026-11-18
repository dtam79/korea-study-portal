<?php
/**
 * Read-only W1-1 data integrity checks. No writes, HTTP requests, or schema DDL.
 * Intended for operator-controlled WP-CLI preflight on an installed WordPress site.
 */

defined('ABSPATH') || exit;

final class KGP_Integrity_Audit {
    private static function count_where(string $from_where): int {
        $result = KGP_DB::one('SELECT COUNT(*) AS quantity ' . $from_where);
        return (int)($result['quantity'] ?? 0);
    }

    private static function add_issue(array &$issues, string $code, int $count, string $description): void {
        if ($count > 0) {
            $issues[] = ['code' => $code, 'count' => $count, 'message' => $description];
        }
    }

    /**
     * @return array{counts:array<string,int>,issues:list<array{code:string,count:int,message:string}>,warnings:list<string>}
     */
    public static function scan(int $max_published_fields = 5000): array {
        if ($max_published_fields < 1 || $max_published_fields > 20000) {
            throw new InvalidArgumentException('Max published fields must be between 1 and 20000.');
        }
        $schema = KGP_Schema::status();
        if (!$schema['ok']) {
            throw new RuntimeException('Schema incomplete. Run wp kgp schema status first.');
        }
        $catalog = KGP_Validator::assert_catalog_covered();
        if ($catalog['sections'] !== 14 || $catalog['fields'] !== 84) {
            throw new RuntimeException('Unexpected DET catalog count. Expect 14 sections / 84 fields.');
        }
        $t = KGP_Schema::names();
        $issues = [];
        $warnings = [];
        $counts = [];
        foreach (['universities','campuses','programs','intakes','offerings','field_entries','sources'] as $key) {
            $counts[$key] = self::count_where('FROM ' . $t[$key]);
        }
        $counts['published_fields'] = self::count_where('FROM ' . $t['field_entries'] . ' WHERE published_version_id IS NOT NULL');
        $counts['published_identities'] = self::count_where('FROM ' . $t['entity_publications'] . ' WHERE published_json IS NOT NULL');
        $counts['det_sections'] = $catalog['sections'];
        $counts['det_fields'] = $catalog['fields'];

        $campus = $t['campuses']; $uni = $t['universities']; $program = $t['programs'];
        $intake = $t['intakes']; $offering = $t['offerings'];
        $entry = $t['field_entries']; $version = $t['field_versions'];

        self::add_issue($issues, 'orphan_campus', self::count_where(
            "FROM $campus c LEFT JOIN $uni u ON u.id = c.university_id WHERE u.id IS NULL"
        ), 'Campus has no university.');
        self::add_issue($issues, 'invalid_program_parent', self::count_where(
            "FROM $program p LEFT JOIN $uni u ON u.id = p.university_id LEFT JOIN $campus c ON c.id = p.campus_id " .
            'WHERE u.id IS NULL OR c.id IS NULL OR c.university_id <> p.university_id'
        ), 'Program has a missing or cross-university campus.');
        self::add_issue($issues, 'orphan_intake', self::count_where(
            "FROM $intake i LEFT JOIN $uni u ON u.id = i.university_id WHERE u.id IS NULL"
        ), 'Intake has no university.');
        self::add_issue($issues, 'invalid_offering_parent', self::count_where(
            "FROM $offering o LEFT JOIN $program p ON p.id = o.program_id " .
            "LEFT JOIN $intake i ON i.id = o.intake_id LEFT JOIN $campus c ON c.id = p.campus_id " .
            'WHERE p.id IS NULL OR i.id IS NULL OR c.id IS NULL ' .
            'OR p.university_id <> i.university_id OR p.university_id <> c.university_id'
        ), 'Offering joins incompatible program, campus or intake.');
        self::add_issue($issues, 'orphan_field_owner', self::count_where(
            "FROM $entry e LEFT JOIN $uni u ON e.entity_type = 'university' AND u.id = e.entity_id " .
            "LEFT JOIN $campus c ON e.entity_type = 'campus' AND c.id = e.entity_id " .
            "LEFT JOIN $program p ON e.entity_type = 'program' AND p.id = e.entity_id " .
            "LEFT JOIN $intake i ON e.entity_type = 'intake' AND i.id = e.entity_id " .
            "LEFT JOIN $offering o ON e.entity_type = 'offering' AND o.id = e.entity_id " .
            "WHERE e.entity_type NOT IN ('university','campus','program','intake','offering') " .
            "OR (e.entity_type = 'university' AND u.id IS NULL) " .
            "OR (e.entity_type = 'campus' AND c.id IS NULL) " .
            "OR (e.entity_type = 'program' AND p.id IS NULL) " .
            "OR (e.entity_type = 'intake' AND i.id IS NULL) " .
            "OR (e.entity_type = 'offering' AND o.id IS NULL)"
        ), 'A field entry references a missing or unsupported entity.');
        self::add_issue($issues, 'broken_published_pointer', self::count_where(
            "FROM $entry e LEFT JOIN $version v ON v.id = e.published_version_id " .
            'WHERE e.published_version_id IS NOT NULL AND ' .
            "(v.id IS NULL OR v.field_entry_id <> e.id OR v.review_status <> 'approved' OR v.datum_status <> 'available')"
        ), 'A published field points to a missing or unapproved version.');

        // Published field values are verified against the *current* 84-field contract.
        // Bounded scan fails closed rather than claiming success on partial inspection.
        $rows = KGP_DB::all(
            "SELECT e.id AS entry_id, e.section_code, e.field_key, e.item_key, " .
            "v.id AS version_id, v.value_type, v.value_json " .
            "FROM $entry e INNER JOIN $version v ON v.id = e.published_version_id " .
            'WHERE e.published_version_id IS NOT NULL ORDER BY e.id ASC LIMIT ' . (int)($max_published_fields + 1)
        );
        if (count($rows) > $max_published_fields) {
            self::add_issue($issues, 'audit_limit_reached', 1, 'Increase --max-fields; this scan was incomplete.');
            $rows = array_slice($rows, 0, $max_published_fields);
        }
        foreach ($rows as $row) {
            $id = (int)$row['entry_id'];
            try {
                $value = json_decode((string)$row['value_json'], true, 20, JSON_THROW_ON_ERROR);
                $checked = KGP_Validator::field(
                    (string)$row['section_code'], (string)$row['field_key'], $value, (string)$row['item_key']
                );
                if ($checked['type'] !== $row['value_type']) {
                    throw new RuntimeException('Published field type differs from its catalog type.');
                }
            } catch (Throwable $e) {
                self::add_issue($issues, 'invalid_published_value', 1, 'Published entry #' . $id . ': ' . $e->getMessage());
            }
            try {
                if (!KGP_Evidence_Repository::verified_sources_for_version((int)$row['version_id'])) {
                    throw new RuntimeException('No currently valid approved official source.');
                }
            } catch (Throwable $e) {
                self::add_issue($issues, 'invalid_published_evidence', 1, 'Published entry #' . $id . ': ' . $e->getMessage());
            }
        }
        // Published identity snapshots need an approved, still-valid source too.
        $identity = $t['entity_publications'];
        $identities = KGP_DB::all(
            "SELECT id, entity_type, entity_id, published_source_id FROM $identity " .
            'WHERE published_json IS NOT NULL ORDER BY id ASC LIMIT ' . (int)($max_published_fields + 1)
        );
        if (count($identities) > $max_published_fields) {
            self::add_issue($issues, 'identity_audit_limit_reached', 1, 'Increase --max-fields; identity scan incomplete.');
            $identities = array_slice($identities, 0, $max_published_fields);
        }
        foreach ($identities as $item) {
            try {
                if ((int)$item['published_source_id'] < 1 ||
                    !KGP_Evidence_Repository::valid_source((int)$item['published_source_id'])) {
                    throw new RuntimeException('Identity source not currently approved.');
                }
            } catch (Throwable $e) {
                self::add_issue(
                    $issues, 'invalid_published_identity_source', 1,
                    'Published identity #' . (int)$item['id'] . ': ' . $e->getMessage()
                );
            }
        }
        if ($counts['offerings'] === 0 || ($counts['published_fields'] === 0 && $counts['published_identities'] === 0)) {
            $warnings[] = 'No real approved Offering/content fixtures to verify; do not mark live-data E2E as complete.';
        }
        return ['counts' => $counts, 'issues' => $issues, 'warnings' => $warnings];
    }
}

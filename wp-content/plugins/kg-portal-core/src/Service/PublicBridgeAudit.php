<?php
/** W2-1 public search/card/detail parity assertions. No WordPress writes. */
defined('ABSPATH') || exit;

final class KGP_Public_Bridge_Audit {
    public const CARD_KEYS = [
        'offering_id', 'university_id', 'campus_name', 'campus_region',
        'program_name', 'field', 'level', 'teaching_languages',
        'english_only', 'tuition', 'certified', 'academic_year',
        'academic_term', 'card_source_checked_at', 'admission', 'locale',
    ];

    /** @return string[] Violations, empty array for an exact card/detail match. */
    public static function inspect(array $card, array $detail, array $catalog): array {
        $issues = [];
        foreach (self::CARD_KEYS as $key) {
            if (!array_key_exists($key, $card) || !array_key_exists($key, $detail)) {
                $issues[] = 'Missing shared card/detail field: ' . $key;
            } elseif ($card[$key] !== $detail[$key]) {
                $issues[] = 'Card/detail mismatch: ' . $key;
            }
        }
        if (!isset($detail['sections']) || !is_array($detail['sections'])) {
            $issues[] = 'Detail sections are missing.';
            return $issues;
        }
        $seen = [];
        foreach ($detail['sections'] as $section) {
            $id = $section['id'] ?? null;
            if (!is_string($id) || !isset($catalog[$id]) || isset($seen[$id])) {
                $issues[] = 'Invalid or duplicate DET section: ' . (string)$id;
                continue;
            }
            $seen[$id] = true;
            if (empty($section['groups']) || !is_array($section['groups'])) {
                $issues[] = $id . ' is public but has no approved groups.';
                continue;
            }
            foreach ($section['groups'] as $group) {
                foreach (($group['fields'] ?? []) as $field) {
                    $key = $field['field'] ?? null;
                    if (!is_string($key) || !isset($catalog[$id]['fields'][$key])) {
                        $issues[] = $id . ' contains an unregistered public field.';
                        continue;
                    }
                    if (!isset($field['source_documents']) || !is_array($field['source_documents']) || count($field['source_documents']) === 0) {
                        $issues[] = $id . '/' . $key . ' has no public source evidence.';
                    }
                }
            }
        }
        return $issues;
    }

    /** Do not require untranslated names to be identical; common values must match. */
    public static function inspect_languages(array $ko, array $en): array {
        $issues = [];
        foreach ([
            'offering_id', 'university_id', 'campus_region', 'field', 'level',
            'academic_year', 'academic_term', 'tuition', 'teaching_languages',
            'english_only', 'certified', 'admission', 'card_source_checked_at',
        ] as $key) {
            if (!array_key_exists($key, $ko) || !array_key_exists($key, $en) || $ko[$key] !== $en[$key]) {
                $issues[] = 'KO/EN shared field mismatch: ' . $key;
            }
        }
        return $issues;
    }

    public static function catalog_coverage(array $sections): array {
        return [
            'sections' => count($sections),
            'fields' => array_sum(array_map(static fn(array $s) => count($s['fields'] ?? []), $sections)),
        ];
    }
}

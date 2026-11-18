<?php
/**
 * W1-2 pilot evidence/read-model audit (read only).
 * An approved source record is NOT independent verification of its truth.
 * This checks implementation readiness for exactly two existing universities.
 */

defined('ABSPATH') || exit;

final class KGP_Pilot_Readiness {
    /**
     * @return array{universities:list<array>,issues:list<string>,warnings:list<string>,technically_ready:bool}
     */
    public static function check(array $university_public_ids): array {
        if (count($university_public_ids) !== 2) {
            throw new InvalidArgumentException('Specify exactly two university public IDs.');
        }
        $ids = [];
        foreach ($university_public_ids as $raw) {
            if (!is_string($raw)) throw new InvalidArgumentException('Public IDs must be strings.');
            $ids[] = KGP_Validator::id(trim($raw));
        }
        if (count(array_unique($ids)) !== 2) {
            throw new InvalidArgumentException('Pilot universities must be distinct.');
        }
        $all_issues = [];
        $all_warnings = [];
        $universities = [];
        $all_offerings = [];
        foreach ($ids as $public_id) {
            global $wpdb;
            $table = KGP_DB::table('universities');
            $row = KGP_DB::one($wpdb->prepare("SELECT id FROM $table WHERE public_id = %s", $public_id));
            $result = ['university_id' => $public_id, 'approved_offerings' => 0,
                'approved_detail_fields' => 0, 'issues' => [], 'warnings' => []];
            if (!$row) {
                $result['issues'][] = 'University does not exist.';
            } else {
                $identity = KGP_Identity_Repository::published('university', (int)$row['id']);
                if (!$identity) {
                    $result['issues'][] = 'University identity is not currently published from verified evidence.';
                } else {
                    if (trim((string)($identity['official_name_en'] ?? '')) === '') {
                        $result['warnings'][] = 'English official name is missing.';
                    }
                    $summary_ko = KGP_Public_Catalog::university($public_id, 'ko');
                    $summary_en = KGP_Public_Catalog::university($public_id, 'en');
                    if (!$summary_ko || !$summary_en) {
                        $result['issues'][] = 'Published university cannot be read in both languages.';
                    } else {
                        $offerings = $summary_ko['offerings'];
                        $result['approved_offerings'] = count($offerings);
                        if (!$offerings) {
                            $result['issues'][] = 'No published Offering with five valid identity snapshots.';
                        }
                        $en_ids = array_column($summary_en['offerings'], 'offering_id');
                        foreach ($offerings as $card) {
                            $offering_id = (string)$card['offering_id'];
                            if (isset($all_offerings[$offering_id])) {
                                $result['issues'][] = 'Offering reused across universities: ' . $offering_id;
                                continue;
                            }
                            $all_offerings[$offering_id] = $public_id;
                            if (!in_array($offering_id, $en_ids, true)) {
                                $result['issues'][] = 'Offering unavailable in English: ' . $offering_id;
                            }
                            if (($card['university_id'] ?? null) !== $public_id) {
                                $result['issues'][] = 'Offering belongs to a different university: ' . $offering_id;
                            }
                            $detail_ko = KGP_Public_Catalog::offering($offering_id, 'ko');
                            $detail_en = KGP_Public_Catalog::offering($offering_id, 'en');
                            if (!$detail_ko || !$detail_en) {
                                $result['issues'][] = 'Approved detail missing in one language: ' . $offering_id;
                                continue;
                            }
                            foreach (['university_id','offering_id','academic_year','academic_term',
                                'teaching_languages','english_only','tuition','certified','admission','card_source_checked_at'] as $key) {
                                if (($detail_ko[$key] ?? null) !== ($detail_en[$key] ?? null)) {
                                    $result['issues'][] = 'KO/EN canonical value differs for ' . $offering_id . ': ' . $key;
                                }
                            }
                            if (empty($card['card_source_checked_at'])) {
                                $result['warnings'][] = 'Card verified-date not available: ' . $offering_id;
                            }
                            $field_count = 0;
                            foreach (($detail_ko['sections'] ?? []) as $section) {
                                foreach (($section['groups'] ?? []) as $group) {
                                    foreach (($group['fields'] ?? []) as $field) {
                                        $field_count++;
                                        $sources = $field['source_documents'] ?? [];
                                        if (!$sources) {
                                            $result['issues'][] = 'Public field has no valid source: ' . $offering_id;
                                        }
                                        foreach ($sources as $source) {
                                            if (empty($source['publisher']) || empty($source['public_url']) || empty($source['checked_at'])) {
                                                $result['issues'][] = 'Published source lacks provenance: ' . $offering_id;
                                            }
                                        }
                                    }
                                }
                            }
                            $result['approved_detail_fields'] += $field_count;
                            if ($field_count === 0) {
                                $result['warnings'][] = 'Offering has no published DET field: ' . $offering_id;
                            }
                        }
                    }
                }
            }
            foreach ($result['issues'] as $issue) $all_issues[] = $public_id . ': ' . $issue;
            foreach ($result['warnings'] as $warning) $all_warnings[] = $public_id . ': ' . $warning;
            $universities[] = $result;
        }
        return ['universities' => $universities, 'issues' => $all_issues,
            'warnings' => $all_warnings, 'technically_ready' => !$all_issues && !$all_warnings];
    }
}

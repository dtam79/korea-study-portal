<?php

defined('ABSPATH') || exit;

/** Entity writes verify all cross-university edges before insertion or update. */
final class KGP_Entity_Repository {
    private const ENTITY_TABLE = [
        'university' => 'universities',
        'campus' => 'campuses',
        'program' => 'programs',
        'intake' => 'intakes',
        'offering' => 'offerings',
    ];

    private static function require_unique(string $table, string $public_id): void {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . KGP_DB::table($table) . ' WHERE public_id = %s', $public_id))) {
            throw new KGP_Domain_Exception('duplicate_id', 'Duplicate public ID in ' . $table);
        }
    }

    public static function create(string $type, array $input): int {
        if (!isset(self::ENTITY_TABLE[$type])) throw new KGP_Domain_Exception('bad_type', 'Unknown entity type.');
        $table = self::ENTITY_TABLE[$type];
        $public_id = KGP_Validator::id((string)($input['public_id'] ?? ''));
        $data = ['public_id' => $public_id];
        if ($type === 'university') {
            KGP_Access::require_cap('kgp_manage_assignments');
            $data += [
                'official_name_ko' => KGP_Validator::plain($input['official_name_ko'] ?? null, 'Korean name', 255),
                'official_name_en' => KGP_Validator::plain($input['official_name_en'] ?? null, 'English name', 255),
                'official_website' => KGP_Validator::url($input['official_website'] ?? null, 'University website'),
            ];
            if (isset($input['institution_type'])) {
                if (!in_array($input['institution_type'], ['public','private','other'], true)) KGP_Validator::fail('Invalid institution type.');
                $data['institution_type'] = $input['institution_type'];
            }
        } elseif ($type === 'campus') {
            $university_id = (int)($input['university_id'] ?? 0);
            KGP_DB::require_row('universities', $university_id);
            KGP_Access::require_edit_for($university_id);
            $data += [
                'university_id' => $university_id,
                'official_name_ko' => KGP_Validator::plain($input['official_name_ko'] ?? null, 'Campus name', 255),
                'official_name_en' => isset($input['official_name_en']) ? KGP_Validator::plain($input['official_name_en'], 'Campus English name', 255) : null,
                'region_code' => isset($input['region_code']) ? KGP_Validator::plain($input['region_code'], 'Region code', 48) : null,
                'is_primary' => (function () use ($input) {
                    $v = $input['is_primary'] ?? 0;
                    if (!in_array($v, [0,1,false,true], true)) KGP_Validator::fail('is_primary must be a boolean.');
                    return (int)$v;
                })(),
            ];
            if (isset($input['address_ko'])) $data['address_ko'] = KGP_Validator::plain($input['address_ko'], 'Campus address', 2000);
            if (isset($input['address_en'])) $data['address_en'] = KGP_Validator::plain($input['address_en'], 'Campus address English', 2000);
        } elseif ($type === 'program') {
            $uni = (int)($input['university_id'] ?? 0);
            $campus = KGP_DB::require_row('campuses', (int)($input['campus_id'] ?? 0));
            KGP_Access::require_edit_for($uni);
            if ((int)$campus['university_id'] !== $uni) KGP_Validator::fail('Program campus belongs to a different university.');
            $data += [
                'university_id' => $uni,
                'campus_id' => (int)$campus['id'],
                'official_name_ko' => KGP_Validator::plain($input['official_name_ko'] ?? null, 'Program name', 255),
                'official_name_en' => isset($input['official_name_en']) ? KGP_Validator::plain($input['official_name_en'], 'Program English name', 255) : null,
                'field_code' => KGP_Validator::plain($input['field_code'] ?? null, 'Field code', 48),
                'level_code' => KGP_Validator::plain($input['level_code'] ?? null, 'Degree level', 48),
                'language_code' => isset($input['language_code']) ? KGP_Validator::check('locale_code', $input['language_code']) : null,
                // English-only must be verified in the field publication workflow before being used publicly.
                'english_only' => null,
            ];
            if (isset($input['department_url'])) $data['department_url'] = KGP_Validator::url($input['department_url'], 'Department URL');
        } elseif ($type === 'intake') {
            $uni = (int)($input['university_id'] ?? 0);
            KGP_DB::require_row('universities', $uni);
            KGP_Access::require_edit_for($uni);
            $year = $input['academic_year'] ?? null;
            KGP_Validator::check('year', $year);
            $data += [
                'university_id' => $uni,
                'academic_year' => $year,
                'academic_term' => KGP_Validator::plain($input['academic_term'] ?? null, 'Academic term', 48),
                'admission_type' => KGP_Validator::plain($input['admission_type'] ?? null, 'Admission type', 48),
                'start_precision' => 'unpublished',
                'end_precision' => 'unpublished',
            ];
            // Do not infer 23:59 or convert a date-only deadline to a timestamp.
            if (isset($input['start_date'])) {
                $data['start_date'] = KGP_Validator::date($input['start_date'], 'Start date');
                $data['start_precision'] = 'day';
            }
            if (isset($input['end_date'])) {
                $data['end_date'] = KGP_Validator::date($input['end_date'], 'End date');
                $data['end_precision'] = 'day';
            }
            if (isset($data['start_date'], $data['end_date']) && $data['start_date'] > $data['end_date']) KGP_Validator::fail('Start date is after end date.');
            if (isset($input['application_url'])) $data['application_url'] = KGP_Validator::url($input['application_url'], 'Application URL');
        } else {
            $program = KGP_DB::require_row('programs', (int)($input['program_id'] ?? 0));
            $intake = KGP_DB::require_row('intakes', (int)($input['intake_id'] ?? 0));
            KGP_Access::require_edit_for((int)$program['university_id']);
            if ((int)$program['university_id'] !== (int)$intake['university_id']) KGP_Validator::fail('Offering program and intake must belong to the same university.');
            $data += [
                'program_id' => (int)$program['id'],
                'intake_id' => (int)$intake['id'],
                'variant_code' => isset($input['variant_code']) ? KGP_Validator::plain($input['variant_code'], 'Offering variant', 64) : 'default',
            ];
        }
        $now = KGP_DB::now();
        $data += ['created_at' => $now, 'updated_at' => $now];
        return KGP_DB::transaction(function () use ($table, $type, $data, $public_id) {
            self::require_unique($table, $public_id);
            $id = KGP_DB::insert($table, $data);
            KGP_DB::audit('entity.created', $type, $id, null, $data);
            return $id;
        });
    }

    /** Update only scalar metadata; relationship IDs and public_id stay immutable. */
    public static function update(string $type, int $id, array $changes, int $expected_revision): array {
        if (!isset(self::ENTITY_TABLE[$type])) throw new KGP_Domain_Exception('bad_type', 'Unknown entity type.');
        $table = self::ENTITY_TABLE[$type];
        $university_id = self::university_id($type, $id);
        if ($type === 'university') {
            KGP_Access::require_cap('kgp_manage_assignments');
        } else {
            KGP_Access::require_edit_for($university_id);
        }
        $editable = [
            'university' => ['official_name_ko','official_name_en','official_website','institution_type'],
            'campus' => ['official_name_ko','official_name_en','region_code','address_ko','address_en','is_primary'],
            'program' => ['official_name_ko','official_name_en','field_code','level_code','language_code','department_url'],
            'intake' => ['academic_term','admission_type','start_date','end_date','application_url'],
            'offering' => ['variant_code'],
        ];
        if (!$changes || array_diff(array_keys($changes), $editable[$type])) {
            KGP_Validator::fail('Unknown, immutable or empty entity update.');
        }
        $clean = [];
        foreach ($changes as $key => $value) {
            if ($value === null && in_array($key, ['address_ko','address_en','region_code','official_name_en','department_url','application_url','language_code','start_date','end_date'], true)) {
                $clean[$key] = null;
            } elseif ($key === 'is_primary') {
                if (!in_array($value, [0,1,false,true], true)) KGP_Validator::fail('Invalid is_primary.');
                $clean[$key] = (int)$value;
            } elseif ($key === 'institution_type') {
                if (!in_array($value, ['public','private','other'], true)) KGP_Validator::fail('Invalid institution type.');
                $clean[$key] = $value;
            } elseif ($key === 'language_code') {
                $clean[$key] = KGP_Validator::check('locale_code', $value);
            } elseif (in_array($key, ['official_website','department_url','application_url'], true)) {
                $clean[$key] = KGP_Validator::url($value, $key);
            } elseif (in_array($key, ['start_date','end_date'], true)) {
                $clean[$key] = KGP_Validator::date($value, $key);
                $clean[$key === 'start_date' ? 'start_precision' : 'end_precision'] = 'day';
            } else {
                $clean[$key] = KGP_Validator::plain($value, $key, in_array($key,['address_ko','address_en'],true) ? 2000 : 255);
            }
        }
        return KGP_DB::transaction(function () use ($table, $type, $id, $clean, $expected_revision) {
            $old = KGP_DB::require_row($table, $id, true);
            if (self::revision($type, $id) !== $expected_revision) throw new KGP_Domain_Exception('version_conflict', 'Entity changed since it was loaded.');
            if ($type === 'intake') {
                $start = $clean['start_date'] ?? $old['start_date'];
                $end = $clean['end_date'] ?? $old['end_date'];
                if ($start && $end && $start > $end) KGP_Validator::fail('Start date after end date.');
                if (array_key_exists('start_date', $clean) && $clean['start_date'] === null) $clean['start_precision'] = 'unpublished';
                if (array_key_exists('end_date', $clean) && $clean['end_date'] === null) $clean['end_precision'] = 'unpublished';
            }
            $data = $clean + ['updated_at' => KGP_DB::now()];
            KGP_DB::update($table, $data, ['id' => $id]);
            KGP_DB::audit('entity.updated', $type, $id, $old, $data);
            $result = KGP_DB::require_row($table, $id);
            $result['revision'] = self::revision($type, $id);
            return $result;
        });
    }

    public static function get(string $type, int $id): array {
        if (!isset(self::ENTITY_TABLE[$type])) throw new KGP_Domain_Exception('bad_type', 'Unknown entity type.');
        // This private repository may be read by an assigned editor or by a reviewer.
        // Never expose raw entity rows through a public REST route.
        $row = KGP_DB::require_row(self::ENTITY_TABLE[$type], $id);
        if (!current_user_can('kgp_review_entries')) {
            KGP_Access::require_edit_for(self::university_id($type, $id));
        }
        $row['revision'] = self::revision($type, $id);
        return $row;
    }

    public static function revision(string $type, int $id): int {
        global $wpdb;
        if (!isset(self::ENTITY_TABLE[$type])) throw new KGP_Domain_Exception('bad_type', 'Unknown entity type.');
        $audit = KGP_DB::table('audit_events');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM $audit WHERE entity_type = %s AND entity_id = %d AND event_type IN ('entity.created','entity.updated')", $type, $id));
    }

    public static function university_id(string $type, int $id): int {
        global $wpdb;
        return match ($type) {
            'university' => (int) KGP_DB::require_row('universities', $id)['id'],
            'campus' => (int) KGP_DB::require_row('campuses', $id)['university_id'],
            'program' => (int) KGP_DB::require_row('programs', $id)['university_id'],
            'intake' => (int) KGP_DB::require_row('intakes', $id)['university_id'],
            'offering' => (function () use ($id) {
                $off = KGP_DB::require_row('offerings', $id);
                $program = KGP_DB::require_row('programs', (int)$off['program_id']);
                $intake = KGP_DB::require_row('intakes', (int)$off['intake_id']);
                if ((int)$program['university_id'] !== (int)$intake['university_id']) KGP_Validator::fail('Broken offering relationship.');
                return (int)$program['university_id'];
            })(),
            default => throw new KGP_Domain_Exception('bad_type', 'Invalid entity type.'),
        };
    }
}

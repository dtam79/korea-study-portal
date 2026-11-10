<?php

defined('ABSPATH') || exit;

/** Draft / review / publish workflow with immutable versions and evidence links. */
final class KGP_Field_Repository {
    private static function entry(int $entry_id, bool $lock = false): array {
        return KGP_DB::require_row('field_entries', $entry_id, $lock);
    }

    private static function require_editor_for_entry(array $entry): void {
        KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($entry['entity_type'], (int)$entry['entity_id']));
    }

    private static function normalized_source_links(array $links): array {
        if (!$links || count($links) > 50) KGP_Validator::fail('At least one official source is required (maximum 50).');
        $out = [];
        foreach ($links as $link) {
            if (!is_array($link) || !isset($link['source_id']) || (int)$link['source_id'] < 1) KGP_Validator::fail('Source link needs source_id.');
            $source_id = (int)$link['source_id'];
            $s = KGP_DB::require_row('sources', $source_id);
            if (preg_match('/\.pdf$/i', (string)(parse_url($s['public_url'], PHP_URL_PATH) ?: '')) && empty($link['original_locator']) && empty($s['document_locator'])) {
                KGP_Validator::fail('PDF source requires an original page or section locator.');
            }
            $out[] = [
                'source_id' => $source_id,
                'original_locator' => isset($link['original_locator']) ? KGP_Validator::plain($link['original_locator'], 'Original locator', 1000) : null,
                'applies_to' => isset($link['applies_to']) ? KGP_Validator::plain($link['applies_to'], 'Source applicability', 2000) : null,
                'valid_from' => isset($link['valid_from']) ? KGP_Validator::date($link['valid_from'], 'Source valid_from') : null,
                'valid_to' => isset($link['valid_to']) ? KGP_Validator::date($link['valid_to'], 'Source valid_to') : null,
            ];
        }
        foreach ($out as $row) {
            if ($row['valid_from'] && $row['valid_to'] && $row['valid_from'] > $row['valid_to']) KGP_Validator::fail('Source link period is invalid.');
        }
        return $out;
    }

    /**
     * @param string $entity_type university|campus|program|intake|offering
     * @param array $source_links [{source_id,original_locator?,applies_to?,valid_from?,valid_to?}]
     * @param ?int $expected_lock Required on existing fields; 0 for new fields.
     * @return array{entry_id:int,version_id:int,version_number:int,lock_version:int}
     */
    public static function save_draft(
        string $entity_type,
        int $entity_id,
        string $section,
        string $key,
        $value,
        array $source_links,
        ?int $expected_lock = null,
        string $item_key = '',
        string $datum_status = 'available'
    ): array {
        KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($entity_type, $entity_id));
        if (!in_array($datum_status, ['available', 'unpublished', 'under_review'], true)) KGP_Validator::fail('Invalid data status.');
        if ($datum_status === 'available') {
            if ($value === null) KGP_Validator::fail('Null is not a published value; use unpublished or under_review status.');
            $checked = KGP_Validator::field($section, $key, $value, $item_key);
        } else {
            if ($value !== null) KGP_Validator::fail('Unavailable/under-review field must store null, not an unsupported claim.');
            // Validate catalog key and item key using a neutral value independent of field type.
            $all = KGP_Field_Catalog::sections();
            if (!isset($all[$section]['fields'][$key])) KGP_Validator::fail('Unknown field.');
            $type = $all[$section]['fields'][$key];
            if (str_starts_with($type, 'repeat:') !== ($item_key !== '')) KGP_Validator::fail('Invalid item_key for field repetition type.');
            $checked = ['type' => $type, 'value' => null];
        }
        $links = $source_links ? self::normalized_source_links($source_links) : [];
        return KGP_DB::transaction(function () use ($entity_type, $entity_id, $section, $key, $item_key, $expected_lock, $datum_status, $checked, $links) {
            global $wpdb;
            $table = KGP_DB::table('field_entries');
            $sql = $wpdb->prepare("SELECT * FROM $table WHERE entity_type = %s AND entity_id = %d AND section_code = %s AND field_key = %s AND item_key = %s FOR UPDATE", $entity_type, $entity_id, $section, $key, $item_key);
            $entry = KGP_DB::one($sql);
            if ($entry) {
                if ($expected_lock === null || $expected_lock !== (int)$entry['lock_version']) {
                    throw new KGP_Domain_Exception('version_conflict', 'Entry lock_version mismatch. Reload before editing.');
                }
                $entry_id = (int)$entry['id'];
            } else {
                if ($expected_lock !== null && $expected_lock !== 0) throw new KGP_Domain_Exception('version_conflict', 'New field expects lock_version 0.');
                $entry_id = KGP_DB::insert('field_entries', [
                    'public_id' => 'KGPF-' . wp_generate_uuid4(),
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'section_code' => $section,
                    'field_key' => $key,
                    'item_key' => $item_key,
                    'lock_version' => 0,
                    'created_at' => KGP_DB::now(),
                    'updated_at' => KGP_DB::now(),
                ]);
            }
            $versions = KGP_DB::table('field_versions');
            $latest = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number),0) FROM $versions WHERE field_entry_id = %d", $entry_id));
            $version_number = $latest + 1;
            $version_id = KGP_DB::insert('field_versions', [
                'field_entry_id' => $entry_id,
                'version_number' => $version_number,
                'value_type' => $checked['type'],
                'value_json' => wp_json_encode($checked['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'datum_status' => $datum_status,
                'review_status' => 'draft',
                'created_by' => get_current_user_id(),
                'created_at' => KGP_DB::now(),
            ]);
            foreach ($links as $source) {
                KGP_DB::insert('field_version_sources', ['field_version_id' => $version_id] + $source + ['created_at' => KGP_DB::now()]);
            }
            $locked_version = $entry ? (int)$entry['lock_version'] + 1 : 1;
            KGP_DB::update('field_entries', [
                'draft_version_id' => $version_id,
                'lock_version' => $locked_version,
                'updated_at' => KGP_DB::now(),
            ], ['id' => $entry_id]);
            KGP_DB::audit('field.draft_saved', $entity_type, $entity_id, null, ['entry_id' => $entry_id, 'version_id' => $version_id, 'version_number' => $version_number]);
            return ['entry_id' => $entry_id, 'version_id' => $version_id, 'version_number' => $version_number, 'lock_version' => $locked_version];
        });
    }

    public static function submit(int $entry_id, int $expected_lock): void {
        KGP_DB::transaction(function () use ($entry_id, $expected_lock) {
            $entry = self::entry($entry_id, true);
            self::require_editor_for_entry($entry);
            if ($expected_lock !== (int)$entry['lock_version']) throw new KGP_Domain_Exception('version_conflict', 'Entry was edited by another user.');
            if (!$entry['draft_version_id']) KGP_Validator::fail('No draft exists.');
            $version = KGP_DB::require_row('field_versions', (int)$entry['draft_version_id']);
            if ($version['review_status'] !== 'draft') KGP_Validator::fail('Only drafts can be submitted.');
            if ($version['datum_status'] !== 'available') KGP_Validator::fail('Unavailable fields cannot be published.');
            global $wpdb;
            $count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . KGP_DB::table('field_version_sources') . ' WHERE field_version_id = %d', $version['id']));
            if ($count < 1) KGP_Validator::fail('Published fields must have at least one official source.');
            KGP_DB::update('field_versions', ['review_status' => 'pending'], ['id' => (int)$version['id']]);
            KGP_DB::update('field_entries', ['lock_version' => $expected_lock + 1, 'updated_at' => KGP_DB::now()], ['id' => $entry_id]);
            KGP_DB::audit('field.submitted', $entry['entity_type'], (int)$entry['entity_id'], ['entry_id' => $entry_id], ['version_id' => (int)$version['id']]);
        });
    }

    public static function publish(int $entry_id): void {
        KGP_Access::require_cap('kgp_review_entries');
        KGP_DB::transaction(function () use ($entry_id) {
            $entry = self::entry($entry_id, true);
            if (!$entry['draft_version_id']) KGP_Validator::fail('No draft exists.');
            $version = KGP_DB::require_row('field_versions', (int)$entry['draft_version_id']);
            if ($version['review_status'] !== 'pending' || $version['datum_status'] !== 'available') KGP_Validator::fail('Only submitted available draft may be approved.');
            if (!KGP_Evidence_Repository::verified_sources_for_version((int)$version['id'])) KGP_Validator::fail('All linked official sources must be verified, current and issued by approved organizations.');
            $before = ['published_version_id' => $entry['published_version_id']];
            KGP_DB::update('field_versions', ['review_status' => 'approved', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => KGP_DB::now(), 'rejection_reason' => null], ['id' => (int)$version['id']]);
            KGP_DB::update('field_entries', [
                'published_version_id' => (int)$version['id'],
                'draft_version_id' => null,
                'lock_version' => (int)$entry['lock_version'] + 1,
                'updated_at' => KGP_DB::now(),
            ], ['id' => $entry_id]);
            KGP_DB::audit('field.published', $entry['entity_type'], (int)$entry['entity_id'], $before, ['published_version_id' => (int)$version['id']]);
            self::signal_public_change($entry);
        });
    }

    public static function reject(int $entry_id, string $reason): void {
        KGP_Access::require_cap('kgp_review_entries');
        $reason = KGP_Validator::plain($reason, 'Rejection reason', 2000);
        KGP_DB::transaction(function () use ($entry_id, $reason) {
            $entry = self::entry($entry_id, true);
            if (!$entry['draft_version_id']) KGP_Validator::fail('No draft exists.');
            $version = KGP_DB::require_row('field_versions', (int)$entry['draft_version_id']);
            if ($version['review_status'] !== 'pending') KGP_Validator::fail('Only submitted drafts can be rejected.');
            KGP_DB::update('field_versions', ['review_status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => KGP_DB::now()], ['id' => (int)$version['id']]);
            KGP_DB::update('field_entries', ['lock_version' => (int)$entry['lock_version'] + 1, 'updated_at' => KGP_DB::now()], ['id' => $entry_id]);
            KGP_DB::audit('field.rejected', $entry['entity_type'], (int)$entry['entity_id'], null, ['entry_id' => $entry_id, 'reason' => $reason]);
        });
    }

    public static function unpublish(int $entry_id, string $reason): void {
        KGP_Access::require_cap('kgp_review_entries');
        KGP_DB::transaction(function () use ($entry_id, $reason) {
            self::unpublish_by_system($entry_id, $reason);
        });
    }

    /** Only public to the invalidation service; not an authorization bypass for endpoints. */
    public static function unpublish_by_system(int $entry_id, string $reason): void {
        $entry = self::entry($entry_id, true);
        if ($entry['published_version_id'] === null) return;
        KGP_DB::update('field_entries', [
            'published_version_id' => null,
            'lock_version' => (int)$entry['lock_version'] + 1,
            'updated_at' => KGP_DB::now(),
        ], ['id' => $entry_id]);
        KGP_DB::audit('field.unpublished', $entry['entity_type'], (int)$entry['entity_id'], ['published_version_id' => $entry['published_version_id']], ['reason' => $reason]);
        self::signal_public_change($entry);
    }

    private static function signal_public_change(array $entry): void {
        // Search index must not be trusted until fully rebuilt with approved values.
        if ($entry['entity_type'] === 'offering') {
            KGP_DB::update('offering_search', ['is_public' => 0], ['offering_id' => (int)$entry['entity_id']]);
        }
        do_action('kgp_published_field_changed', $entry['entity_type'], (int)$entry['entity_id'], (int)$entry['id']);
    }
}

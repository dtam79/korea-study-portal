<?php

defined('ABSPATH') || exit;

final class KGP_Evidence_Repository {
    public static function create_issuer(array $input): int {
        KGP_Access::require_cap('kgp_manage_issuers');
        $type = $input['issuer_type'] ?? null;
        if (!in_array($type, ['university', 'government', 'public_agency', 'accredited_evaluator', 'official_application_provider'], true)) KGP_Validator::fail('Invalid issuer type.');
        $id = KGP_DB::insert('issuers', [
            'public_id' => KGP_Validator::id($input['public_id'] ?? ''),
            'official_name' => KGP_Validator::plain($input['official_name'] ?? null, 'Issuer name', 255),
            'issuer_type' => $type,
            'official_domain' => isset($input['official_domain']) ? KGP_Validator::plain($input['official_domain'], 'Official domain', 255) : null,
            'approval_status' => 'pending',
            'created_at' => KGP_DB::now(),
        ]);
        KGP_DB::audit('issuer.created', 'issuer', $id);
        return $id;
    }

    public static function approve_issuer(int $issuer_id): void {
        KGP_Access::require_cap('kgp_manage_issuers');
        KGP_DB::transaction(function () use ($issuer_id) {
            KGP_DB::require_row('issuers', $issuer_id, true);
            KGP_DB::update('issuers', ['approval_status' => 'approved', 'approved_by' => get_current_user_id(), 'approved_at' => KGP_DB::now()], ['id' => $issuer_id]);
            KGP_DB::audit('issuer.approved', 'issuer', $issuer_id);
        });
    }

    public static function create_source(array $input): int {
        KGP_Access::require_cap('kgp_edit_entries');
        KGP_DB::require_row('issuers', (int)($input['issuer_id'] ?? 0));
        if (isset($input['valid_from'], $input['valid_to']) && $input['valid_from'] > $input['valid_to']) KGP_Validator::fail('Source valid_from exceeds valid_to.');
        $id = KGP_DB::insert('sources', [
            'public_id' => KGP_Validator::id($input['public_id'] ?? ''),
            'issuer_id' => (int)$input['issuer_id'],
            'document_title' => KGP_Validator::plain($input['document_title'] ?? null, 'Document title', 500),
            'public_url' => KGP_Validator::url($input['public_url'] ?? null, 'Public source URL'),
            'document_locator' => isset($input['document_locator']) ? KGP_Validator::plain($input['document_locator'], 'Document locator', 1000) : null,
            'applicable_to' => isset($input['applicable_to']) ? KGP_Validator::plain($input['applicable_to'], 'Applicability', 2000) : null,
            'valid_from' => isset($input['valid_from']) ? KGP_Validator::date($input['valid_from'], 'valid_from') : null,
            'valid_to' => isset($input['valid_to']) ? KGP_Validator::date($input['valid_to'], 'valid_to') : null,
            'checked_at' => null,
            'evidence_status' => 'unchecked',
            'created_at' => KGP_DB::now(),
            'updated_at' => KGP_DB::now(),
        ]);
        KGP_DB::audit('source.created', 'source', $id);
        return $id;
    }

    public static function verify_source(int $source_id): void {
        KGP_Access::require_cap('kgp_verify_sources');
        KGP_DB::transaction(function () use ($source_id) {
            $source = KGP_DB::require_row('sources', $source_id, true);
            $issuer = KGP_DB::require_row('issuers', (int)$source['issuer_id']);
            if ($issuer['approval_status'] !== 'approved') KGP_Validator::fail('Issuer has not been approved.');
            if (!$source['document_title'] || !$source['public_url']) KGP_Validator::fail('Source lacks public document evidence.');
            if ($source['valid_to'] !== null && $source['valid_to'] < gmdate('Y-m-d')) KGP_Validator::fail('Expired source cannot be verified.');
            KGP_DB::update('sources', [
                'evidence_status' => 'verified',
                'checked_at' => KGP_DB::now(),
                'checked_by' => get_current_user_id(),
                'verified_by' => get_current_user_id(),
                'verified_at' => KGP_DB::now(),
                'updated_at' => KGP_DB::now(),
                'invalid_reason' => null,
            ], ['id' => $source_id]);
            KGP_DB::audit('source.verified', 'source', $source_id);
        });
    }

    public static function valid_source(int $source_id): bool {
        $source = KGP_DB::require_row('sources', $source_id);
        $issuer = KGP_DB::require_row('issuers', (int)$source['issuer_id']);
        if ($issuer['approval_status'] !== 'approved' || $source['evidence_status'] !== 'verified' || !$source['verified_by'] || !$source['verified_at'] || !$source['public_url']) return false;
        $today = gmdate('Y-m-d');
        if ($source['valid_to'] !== null && $source['valid_to'] < $today) return false;
        if ($source['valid_from'] !== null && $source['valid_from'] > $today) return false;
        return true;
    }

    public static function verified_sources_for_version(int $version_id): array {
        global $wpdb;
        $table = KGP_DB::table('field_version_sources');
        $links = KGP_DB::all($wpdb->prepare("SELECT * FROM $table WHERE field_version_id = %d ORDER BY id", $version_id));
        if (!$links) return [];
        $items = [];
        foreach ($links as $link) {
            if (!self::valid_source((int)$link['source_id'])) return [];
            if ($link['valid_to'] !== null && $link['valid_to'] < gmdate('Y-m-d')) return [];
            if ($link['valid_from'] !== null && $link['valid_from'] > gmdate('Y-m-d')) return [];
            $source = KGP_DB::require_row('sources', (int)$link['source_id']);
            $items[] = [
                'source_id' => (int)$source['id'],
                'title' => $source['document_title'],
                'public_url' => $source['public_url'],
                'document_locator' => $link['original_locator'] ?: $source['document_locator'],
                'checked_at' => $source['checked_at'],
                'applicability' => $link['applies_to'] ?: $source['applicable_to'],
                'valid_from' => $link['valid_from'] ?: $source['valid_from'],
                'valid_to' => $link['valid_to'] ?: $source['valid_to'],
            ];
        }
        return $items;
    }

    public static function invalidate_source(int $source_id, string $reason): int {
        KGP_Access::require_cap('kgp_verify_sources');
        $reason = KGP_Validator::plain($reason, 'Invalidation reason', 2000);
        return KGP_DB::transaction(function () use ($source_id, $reason) {
            global $wpdb;
            $s = KGP_DB::require_row('sources', $source_id, true);
            KGP_DB::update('sources', ['evidence_status' => 'invalid', 'invalid_reason' => $reason, 'updated_at' => KGP_DB::now()], ['id' => $source_id]);
            $link = KGP_DB::table('field_version_sources');
            $entries = KGP_DB::table('field_entries');
            $found = KGP_DB::all($wpdb->prepare(
                "SELECT DISTINCT e.id FROM $entries e INNER JOIN $link l ON l.field_version_id = e.published_version_id WHERE l.source_id = %d", $source_id
            ));
            foreach ($found as $row) {
                KGP_Field_Repository::unpublish_by_system((int)$row['id'], 'source_invalidated:' . $source_id);
            }
            KGP_DB::audit('source.invalidated', 'source', $source_id, $s, ['reason' => $reason, 'affected_fields' => count($found)]);
            return count($found);
        });
    }
}

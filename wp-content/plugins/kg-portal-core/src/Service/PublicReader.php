<?php

defined('ABSPATH') || exit;

/** Only approved current field versions with valid, public evidence are returned. */
final class KGP_Public_Reader {
    public static function field(string $entity_type, int $entity_id, string $section, string $key, string $item_key = '', string $locale = 'ko'): ?array {
        global $wpdb;
        if (!in_array($locale, ['ko', 'en'], true)) KGP_Validator::fail('Unsupported language.');
        if (!KGP_Field_Catalog::has_field($section, $key)) KGP_Validator::fail('Unknown field.');
        $entries = KGP_DB::table('field_entries');
        $row = KGP_DB::one($wpdb->prepare(
            "SELECT * FROM $entries WHERE entity_type = %s AND entity_id = %d AND section_code = %s AND field_key = %s AND item_key = %s",
            $entity_type, $entity_id, $section, $key, $item_key
        ));
        if (!$row || !$row['published_version_id']) return null;
        $version = KGP_DB::require_row('field_versions', (int)$row['published_version_id']);
        if ($version['review_status'] !== 'approved' || $version['datum_status'] !== 'available') return null;
        $sources = KGP_Evidence_Repository::verified_sources_for_version((int)$version['id']);
        if (!$sources) return null;
        $value = json_decode((string)$version['value_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || $value === null) return null;
        $fallback = false;
        if ($locale === 'en' && self::translatable($version['value_type'])) {
            $translations = KGP_DB::table('translations');
            $t = KGP_DB::one($wpdb->prepare("SELECT translated_json FROM $translations WHERE field_version_id = %d AND locale = %s AND status = %s", (int)$version['id'], $locale, 'approved'));
            if ($t) {
                $translated = json_decode($t['translated_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && $translated !== null) {
                    $value = $translated;
                } else {
                    $fallback = true;
                }
            } else {
                $fallback = true;  // Display current original with translation-pending indicator, never stale text.
            }
        }
        return [
            'entry_id' => (int)$row['id'],
            'version_id' => (int)$version['id'],
            'section' => $row['section_code'],
            'field' => $row['field_key'],
            'item_key' => $row['item_key'],
            'value_type' => $version['value_type'],
            'value' => $value,
            'source_documents' => $sources,
            'locale' => $locale,
            'translation_pending' => $fallback,
        ];
    }

    private static function translatable(string $type): bool {
        return $type === 'localized_text' || str_starts_with($type, 'repeat:localized_text') || in_array($type, [
            'official_address', 'official_contact', 'document', 'scholarship', 'structured_schedule',
        ], true) || preg_match('/^repeat:(?:official_|document|scholarship|language_requirement|waiver|certification|restriction|published_statistic)/', $type) === 1;
    }

    public static function section(string $entity_type, int $entity_id, string $section, string $locale = 'ko'): array {
        global $wpdb;
        if (!isset(KGP_Field_Catalog::sections()[$section])) KGP_Validator::fail('Invalid DET section.');
        $table = KGP_DB::table('field_entries');
        $rows = KGP_DB::all($wpdb->prepare(
            "SELECT field_key,item_key FROM $table WHERE entity_type = %s AND entity_id = %d AND section_code = %s AND published_version_id IS NOT NULL ORDER BY field_key,item_key",
            $entity_type, $entity_id, $section
        ));
        $public = [];
        foreach ($rows as $row) {
            $item = self::field($entity_type, $entity_id, $section, $row['field_key'], $row['item_key'], $locale);
            if ($item !== null) $public[] = $item;
        }
        return $public;
    }
}

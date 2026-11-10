<?php
/**
 * Non-destructive database schema for KGP. Base entity tables are private inputs;
 * no public API is registered by this package. Never read draft base data directly
 * for public pages: publication service must enforce approved per-field provenance.
 */

defined('ABSPATH') || exit;

final class KGP_Schema {
    public const VERSION = '2026.11.10.1';
    public const OPTION = 'kgp_db_schema_version';

    public static function activate(): void {
        $result = self::install();
        if (!$result['ok']) {
            error_log('KGP schema installation incomplete: ' . implode(', ', $result['missing']));
        }
    }

    /** @return array<string,string> */
    public static function names(): array {
        global $wpdb;
        $suffixes = [
            'universities', 'campuses', 'programs', 'intakes', 'offerings',
            'issuers', 'sources', 'field_entries', 'field_versions',
            'field_version_sources', 'translations', 'offering_search',
            'user_assignments', 'audit_events', 'import_batches',
            'import_rows', 'inquiries', 'inquiry_events', 'recheck_tasks',
        ];
        $result = [];
        foreach ($suffixes as $suffix) {
            $result[$suffix] = $wpdb->prefix . 'kgp_' . $suffix;
        }
        return $result;
    }

    /** @return string[] */
    public static function sql(): array {
        global $wpdb;
        $t = self::names();
        $cc = $wpdb->get_charset_collate();
        // dbDelta requires one field per line and PRIMARY KEY with two spaces.
        return [
            "CREATE TABLE {$t['universities']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                official_name_ko varchar(255) NOT NULL,
                official_name_en varchar(255) NOT NULL,
                official_website varchar(500) NOT NULL,
                institution_type varchar(32) DEFAULT NULL,
                logo_attachment_id bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY name_ko (official_name_ko(120)),
                KEY name_en (official_name_en(120))
            ) $cc;",
            "CREATE TABLE {$t['campuses']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                university_id bigint(20) unsigned NOT NULL,
                official_name_ko varchar(255) NOT NULL,
                official_name_en varchar(255) DEFAULT NULL,
                region_code varchar(48) DEFAULT NULL,
                address_ko text NULL,
                address_en text NULL,
                is_primary tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY university_id (university_id),
                KEY region_code (region_code)
            ) $cc;",
            "CREATE TABLE {$t['programs']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                university_id bigint(20) unsigned NOT NULL,
                campus_id bigint(20) unsigned NOT NULL,
                official_name_ko varchar(255) NOT NULL,
                official_name_en varchar(255) DEFAULT NULL,
                field_code varchar(48) NOT NULL,
                level_code varchar(48) NOT NULL,
                language_code varchar(48) DEFAULT NULL,
                english_only tinyint(1) DEFAULT NULL,
                department_url varchar(500) DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY university_campus (university_id,campus_id),
                KEY field_level (field_code,level_code)
            ) $cc;",
            "CREATE TABLE {$t['intakes']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                university_id bigint(20) unsigned NOT NULL,
                academic_year smallint(5) unsigned NOT NULL,
                academic_term varchar(48) NOT NULL,
                admission_type varchar(48) NOT NULL,
                start_date date DEFAULT NULL,
                end_date date DEFAULT NULL,
                starts_at_utc datetime DEFAULT NULL,
                ends_at_utc datetime DEFAULT NULL,
                start_precision varchar(24) NOT NULL DEFAULT 'unpublished',
                end_precision varchar(24) NOT NULL DEFAULT 'unpublished',
                timezone_name varchar(64) DEFAULT NULL,
                application_url varchar(500) DEFAULT NULL,
                recruitment_override varchar(32) DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY university_year (university_id,academic_year),
                KEY date_range (start_date,end_date)
            ) $cc;",
            "CREATE TABLE {$t['offerings']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                program_id bigint(20) unsigned NOT NULL,
                intake_id bigint(20) unsigned NOT NULL,
                variant_code varchar(64) NOT NULL DEFAULT 'default',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY offering_tuple (program_id,intake_id,variant_code),
                KEY intake_id (intake_id)
            ) $cc;",
            "CREATE TABLE {$t['issuers']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                official_name varchar(255) NOT NULL,
                issuer_type varchar(48) NOT NULL,
                official_domain varchar(255) DEFAULT NULL,
                approval_status varchar(32) NOT NULL DEFAULT 'pending',
                approved_by bigint(20) unsigned DEFAULT NULL,
                approved_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY issuer_type (issuer_type),
                KEY approval_status (approval_status)
            ) $cc;",
            "CREATE TABLE {$t['sources']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                issuer_id bigint(20) unsigned NOT NULL,
                document_title varchar(500) NOT NULL,
                public_url varchar(1000) NOT NULL,
                document_locator text NULL,
                applicable_to text NULL,
                valid_from date DEFAULT NULL,
                valid_to date DEFAULT NULL,
                checked_at datetime DEFAULT NULL,
                checked_by bigint(20) unsigned DEFAULT NULL,
                verified_by bigint(20) unsigned DEFAULT NULL,
                verified_at datetime DEFAULT NULL,
                evidence_status varchar(32) NOT NULL DEFAULT 'unchecked',
                invalid_reason text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY issuer_status (issuer_id,evidence_status),
                KEY checked_at (checked_at)
            ) $cc;",
            "CREATE TABLE {$t['field_entries']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                entity_type varchar(32) NOT NULL,
                entity_id bigint(20) unsigned NOT NULL,
                section_code varchar(8) NOT NULL,
                field_key varchar(96) NOT NULL,
                item_key varchar(64) NOT NULL DEFAULT '',
                draft_version_id bigint(20) unsigned DEFAULT NULL,
                published_version_id bigint(20) unsigned DEFAULT NULL,
                lock_version bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY unique_field (entity_type,entity_id,section_code,field_key,item_key),
                KEY published_version (published_version_id),
                KEY draft_version (draft_version_id)
            ) $cc;",
            "CREATE TABLE {$t['field_versions']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                field_entry_id bigint(20) unsigned NOT NULL,
                version_number bigint(20) unsigned NOT NULL,
                value_type varchar(32) NOT NULL,
                value_json longtext NULL,
                datum_status varchar(32) NOT NULL DEFAULT 'unverified',
                review_status varchar(32) NOT NULL DEFAULT 'draft',
                created_by bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                reviewed_by bigint(20) unsigned DEFAULT NULL,
                reviewed_at datetime DEFAULT NULL,
                rejection_reason text NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY entry_revision (field_entry_id,version_number),
                KEY review_status (review_status),
                KEY created_at (created_at)
            ) $cc;",
            "CREATE TABLE {$t['field_version_sources']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                field_version_id bigint(20) unsigned NOT NULL,
                source_id bigint(20) unsigned NOT NULL,
                original_locator text NULL,
                applies_to text NULL,
                valid_from date DEFAULT NULL,
                valid_to date DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY version_source (field_version_id,source_id),
                KEY source_id (source_id)
            ) $cc;",
            "CREATE TABLE {$t['translations']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                field_version_id bigint(20) unsigned NOT NULL,
                locale varchar(12) NOT NULL,
                translated_json longtext NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'draft',
                translator_id bigint(20) unsigned DEFAULT NULL,
                reviewer_id bigint(20) unsigned DEFAULT NULL,
                reviewed_at datetime DEFAULT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY version_locale (field_version_id,locale),
                KEY locale_status (locale,status)
            ) $cc;",
            "CREATE TABLE {$t['offering_search']} (
                offering_id bigint(20) unsigned NOT NULL,
                university_id bigint(20) unsigned NOT NULL,
                campus_id bigint(20) unsigned NOT NULL,
                program_id bigint(20) unsigned NOT NULL,
                intake_id bigint(20) unsigned NOT NULL,
                university_name_ko varchar(255) DEFAULT NULL,
                university_name_en varchar(255) DEFAULT NULL,
                region_code varchar(48) DEFAULT NULL,
                field_code varchar(48) DEFAULT NULL,
                level_code varchar(48) DEFAULT NULL,
                language_code varchar(48) DEFAULT NULL,
                english_only tinyint(1) DEFAULT NULL,
                tuition_min decimal(16,2) DEFAULT NULL,
                tuition_max decimal(16,2) DEFAULT NULL,
                tuition_unit varchar(32) DEFAULT NULL,
                recruitment_status varchar(32) DEFAULT NULL,
                registration_closes_at_utc datetime DEFAULT NULL,
                verified_at datetime DEFAULT NULL,
                publication_token varchar(64) DEFAULT NULL,
                is_public tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (offering_id),
                KEY public_filter (is_public,region_code,field_code,level_code),
                KEY university_id (university_id),
                KEY tuition_sort (tuition_unit,tuition_min),
                KEY recruitment_sort (recruitment_status,registration_closes_at_utc)
            ) $cc;",
            "CREATE TABLE {$t['user_assignments']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                university_id bigint(20) unsigned NOT NULL,
                role_code varchar(32) NOT NULL,
                assigned_by bigint(20) unsigned DEFAULT NULL,
                assigned_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_assignment (user_id,university_id,role_code),
                KEY university_id (university_id)
            ) $cc;",
            "CREATE TABLE {$t['audit_events']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                actor_id bigint(20) unsigned DEFAULT NULL,
                event_type varchar(64) NOT NULL,
                entity_type varchar(32) NOT NULL,
                entity_id bigint(20) unsigned NOT NULL,
                before_json longtext NULL,
                after_json longtext NULL,
                details_json longtext NULL,
                recorded_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY entity_event (entity_type,entity_id),
                KEY actor_time (actor_id,recorded_at)
            ) $cc;",
            "CREATE TABLE {$t['import_batches']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                file_sha256 char(64) NOT NULL,
                original_filename varchar(255) NOT NULL,
                requested_by bigint(20) unsigned NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'preview',
                preview_json longtext NULL,
                result_json longtext NULL,
                created_at datetime NOT NULL,
                applied_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY file_hash (file_sha256),
                KEY requested_by (requested_by)
            ) $cc;",
            "CREATE TABLE {$t['import_rows']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                batch_id bigint(20) unsigned NOT NULL,
                csv_row_number int(11) unsigned NOT NULL,
                entity_type varchar(32) DEFAULT NULL,
                entity_public_id varchar(64) DEFAULT NULL,
                action_code varchar(32) DEFAULT NULL,
                status varchar(32) NOT NULL DEFAULT 'preview',
                normalized_json longtext NULL,
                error_json longtext NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY batch_row (batch_id,csv_row_number),
                KEY batch_status (batch_id,status)
            ) $cc;",
            "CREATE TABLE {$t['inquiries']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                receipt_code varchar(40) NOT NULL,
                email varchar(254) NOT NULL,
                inquiry_locale varchar(12) NOT NULL,
                message_body longtext NOT NULL,
                university_id bigint(20) unsigned DEFAULT NULL,
                program_id bigint(20) unsigned DEFAULT NULL,
                preferred_intake varchar(100) DEFAULT NULL,
                consent_policy_version varchar(64) NOT NULL,
                consented_at datetime NOT NULL,
                assigned_to bigint(20) unsigned DEFAULT NULL,
                handling_status varchar(32) NOT NULL DEFAULT 'new',
                email_delivery_status varchar(32) NOT NULL DEFAULT 'not_attempted',
                delete_after datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY receipt_code (receipt_code),
                KEY assigned_status (assigned_to,handling_status),
                KEY delete_after (delete_after)
            ) $cc;",
            "CREATE TABLE {$t['inquiry_events']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                inquiry_id bigint(20) unsigned NOT NULL,
                actor_id bigint(20) unsigned DEFAULT NULL,
                event_type varchar(48) NOT NULL,
                internal_note text NULL,
                recorded_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY inquiry_id (inquiry_id),
                KEY recorded_at (recorded_at)
            ) $cc;",
            "CREATE TABLE {$t['recheck_tasks']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                entity_type varchar(32) NOT NULL,
                entity_id bigint(20) unsigned NOT NULL,
                field_entry_id bigint(20) unsigned DEFAULT NULL,
                assignee_id bigint(20) unsigned DEFAULT NULL,
                reason_code varchar(48) NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'open',
                due_at datetime DEFAULT NULL,
                resolved_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY due_status (status,due_at),
                KEY entity_ref (entity_type,entity_id),
                KEY assignee_id (assignee_id)
            ) $cc;",
        ];
    }

    /** @return array{ok:bool,missing:string[],changed:array} */
    public static function install(): array {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $changed = dbDelta(self::sql());
        $status = self::status();
        if ($status['ok']) {
            update_option(self::OPTION, self::VERSION, false);
        }
        return ['ok' => $status['ok'], 'missing' => $status['missing'], 'changed' => $changed];
    }

    /** @return array{ok:bool,installed_version:string,expected_version:string,existing:string[],missing:string[]} */
    public static function status(): array {
        global $wpdb;
        $existing = [];
        $missing = [];
        foreach (self::names() as $name) {
            // Table names are generated by code, not supplied by users.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name)));
            if ($found === $name) {
                $existing[] = $name;
            } else {
                $missing[] = $name;
            }
        }
        return [
            'ok' => empty($missing),
            'installed_version' => (string) get_option(self::OPTION, 'not-installed'),
            'expected_version' => self::VERSION,
            'existing' => $existing,
            'missing' => $missing,
        ];
    }
}

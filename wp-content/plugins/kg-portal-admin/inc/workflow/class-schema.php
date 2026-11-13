<?php
defined('ABSPATH') || exit;

/** Addon-owned workflow tables. Never alter or drop Core's 20 tables. */
final class KGP_Operator_Workflow_Schema {
    public const VERSION = '0.7.0';
    public static function table(string $name): string {
        if (!in_array($name, ['translation_drafts','csv_batches','csv_rows'], true)) throw new InvalidArgumentException('Unknown addon table.');
        global $wpdb;
        return $wpdb->prefix . 'kgpa_' . $name;
    }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $tr=self::table('translation_drafts'); $b=self::table('csv_batches'); $r=self::table('csv_rows');
        dbDelta("CREATE TABLE $tr (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            field_version_id bigint(20) unsigned NOT NULL,
            locale varchar(12) NOT NULL,
            translated_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_by bigint(20) unsigned NOT NULL,
            submitted_by bigint(20) unsigned DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            rejection_reason text NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY version_locale (field_version_id,locale),
            KEY status_updated (status,updated_at)
        ) $cc;");
        dbDelta("CREATE TABLE $b (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            file_hash char(64) NOT NULL,
            original_filename varchar(255) NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'preview',
            row_count int(11) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            applied_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY creator_file (created_by,file_hash),
            KEY creator_status (created_by,status)
        ) $cc;");
        dbDelta("CREATE TABLE $r (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_id bigint(20) unsigned NOT NULL,
            csv_line int(11) unsigned NOT NULL,
            entity_type varchar(32) NOT NULL,
            public_id varchar(64) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'ready',
            action_code varchar(24) NOT NULL DEFAULT 'create',
            normalized_json longtext NULL,
            before_json longtext NULL,
            error_json longtext NULL,
            result_id bigint(20) unsigned DEFAULT NULL,
            applied_by bigint(20) unsigned DEFAULT NULL,
            applied_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_line (batch_id,csv_line),
            KEY batch_status (batch_id,status)
        ) $cc;");
        update_option('kgpa_workflow_schema_version',self::VERSION,false);
    }
    public static function ensure(): void {
        if (get_option('kgpa_workflow_schema_version') !== self::VERSION) self::install();
    }
}

<?php
defined('ABSPATH') || exit;
/** Addon-owned tables, deliberately isolated from the core data schema. */
final class KGPO_Schema {
    public const VERSION='1.0.0';
    public static function table(string $name): string {
        if (!in_array($name,['source_state','source_checks','review_tasks'],true)) throw new InvalidArgumentException('Invalid operations table.');
        global $wpdb;return $wpdb->prefix.'kgpo_'.$name;
    }
    public static function activate(): void { self::install(); }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $cc=$wpdb->get_charset_collate();
        $s=self::table('source_state');$c=self::table('source_checks');$t=self::table('review_tasks');
        dbDelta("CREATE TABLE $s (
            source_id bigint(20) unsigned NOT NULL,
            category varchar(36) NOT NULL DEFAULT 'other',
            cadence_days int(11) unsigned NOT NULL DEFAULT 90,
            assigned_to bigint(20) unsigned DEFAULT NULL,
            next_check_at datetime NOT NULL,
            last_checked_at datetime DEFAULT NULL,
            last_result varchar(24) DEFAULT NULL,
            last_http_status int(11) DEFAULT NULL,
            etag varchar(200) DEFAULT NULL,
            last_modified varchar(100) DEFAULT NULL,
            content_hash char(64) DEFAULT NULL,
            sample_size int(11) unsigned NOT NULL DEFAULT 0,
            baseline_created_at datetime DEFAULT NULL,
            failure_count int(11) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (source_id),
            KEY next_check_at (next_check_at),
            KEY category_due (category,next_check_at)
        ) $cc;");
        dbDelta("CREATE TABLE $c (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            checked_at datetime NOT NULL,
            result_code varchar(24) NOT NULL,
            http_status int(11) DEFAULT NULL,
            etag varchar(200) DEFAULT NULL,
            last_modified varchar(100) DEFAULT NULL,
            content_hash char(64) DEFAULT NULL,
            sample_size int(11) unsigned NOT NULL DEFAULT 0,
            error_code varchar(100) DEFAULT NULL,
            before_json longtext NULL,
            after_json longtext NULL,
            PRIMARY KEY  (id),
            KEY source_checked (source_id,checked_at),
            KEY result_checked (result_code,checked_at)
        ) $cc;");
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            reason_code varchar(32) NOT NULL,
            status_code varchar(20) NOT NULL DEFAULT 'pending',
            open_slot tinyint(1) unsigned DEFAULT 1,
            assigned_to bigint(20) unsigned DEFAULT NULL,
            opened_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            resolved_at datetime DEFAULT NULL,
            resolved_by bigint(20) unsigned DEFAULT NULL,
            resolution_code varchar(32) DEFAULT NULL,
            review_note text NULL,
            check_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_updated (status_code,updated_at),
            KEY source_reason (source_id,reason_code),
            UNIQUE KEY active_reason (source_id,reason_code,open_slot),
            KEY source_pending (source_id,status_code)
        ) $cc;");
        update_option('kgpo_schema_version',self::VERSION,false);
        if (!wp_next_scheduled('kgpo_scan_due')) wp_schedule_event(time()+300,'hourly','kgpo_scan_due');
    }
    public static function ready(): bool {return get_option('kgpo_schema_version')===self::VERSION;}
    public static function maybe_upgrade(): void {
        if (!current_user_can('manage_options')) return;
        if (!self::ready()) self::install();
        if (!wp_next_scheduled('kgpo_scan_due')) wp_schedule_event(time()+300,'hourly','kgpo_scan_due');
    }
    public static function deactivate(): void {wp_clear_scheduled_hook('kgpo_scan_due');}
}

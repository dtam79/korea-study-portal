<?php
defined('ABSPATH') || exit;

final class KGP_Operator_Inquiry_Schema {
    public const VERSION='0.8.0';
    public static function table(string $name): string {
        if ($name!=='deletion_audit') throw new InvalidArgumentException('Unknown inquiry table.');
        global $wpdb;
        return $wpdb->prefix.'kgpa_inquiry_deletion_audit';
    }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table('deletion_audit');$cc=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            receipt_hash char(64) NOT NULL,
            actor_id bigint(20) unsigned DEFAULT NULL,
            reason_code varchar(32) NOT NULL,
            erased_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY erased_at (erased_at),
            KEY receipt_hash (receipt_hash)
        ) $cc;");
        update_option('kgpa_inquiry_schema_version',self::VERSION,false);
        if (!wp_next_scheduled('kgpa_inquiry_retention_cleanup')) {
            wp_schedule_event(time()+300,'daily','kgpa_inquiry_retention_cleanup');
        }
    }
    public static function ensure(): void {
        if (get_option('kgpa_inquiry_schema_version')!==self::VERSION) self::install();
        elseif (!wp_next_scheduled('kgpa_inquiry_retention_cleanup')) {
            wp_schedule_event(time()+300,'daily','kgpa_inquiry_retention_cleanup');
        }
    }
    public static function deactivate(): void {wp_clear_scheduled_hook('kgpa_inquiry_retention_cleanup');}
}

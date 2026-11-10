<?php

defined('ABSPATH') || exit;

/** Nested-safe InnoDB transactions. Never run schema DDL inside a transaction. */
final class KGP_DB {
    private static int $level = 0;

    public static function table(string $name): string {
        $tables = KGP_Schema::names();
        if (!isset($tables[$name])) {
            throw new KGP_Domain_Exception('bad_table', 'Unknown KGP table.');
        }
        return $tables[$name];
    }

    public static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function must_query(string $sql): void {
        global $wpdb;
        if (false === $wpdb->query($sql)) {
            throw new KGP_Domain_Exception('database_error', 'Database query failed: ' . $wpdb->last_error);
        }
    }

    public static function insert(string $table, array $data): int {
        global $wpdb;
        if (false === $wpdb->insert(self::table($table), $data)) {
            throw new KGP_Domain_Exception('database_error', 'Insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    public static function update(string $table, array $data, array $where): int {
        global $wpdb;
        $result = $wpdb->update(self::table($table), $data, $where);
        if (false === $result) {
            throw new KGP_Domain_Exception('database_error', 'Update failed: ' . $wpdb->last_error);
        }
        return (int) $result;
    }

    public static function require_row(string $table, int $id, bool $locked = false): array {
        global $wpdb;
        if ($id < 1) {
            throw new KGP_Domain_Exception('not_found', 'Invalid object ID.');
        }
        $sql = $wpdb->prepare('SELECT * FROM ' . self::table($table) . ' WHERE id = %d' . ($locked ? ' FOR UPDATE' : ''), $id);
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) {
            throw new KGP_Domain_Exception('not_found', 'Object not found: ' . $table . '/' . $id);
        }
        return $row;
    }

    public static function one(string $sql): ?array {
        global $wpdb;
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new KGP_Domain_Exception('database_error', $wpdb->last_error);
        }
        return $row ?: null;
    }

    public static function all(string $sql): array {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new KGP_Domain_Exception('database_error', $wpdb->last_error);
        }
        return $rows ?: [];
    }

    public static function transaction(callable $callback) {
        $savepoint = 'kgp_sp_' . self::$level;
        if (self::$level === 0) {
            self::must_query('START TRANSACTION');
        } else {
            self::must_query('SAVEPOINT ' . $savepoint);
        }
        ++self::$level;
        try {
            $result = $callback();
            --self::$level;
            self::must_query(self::$level === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $e) {
            --self::$level;
            self::must_query(self::$level === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $savepoint);
            throw $e;
        }
    }

    public static function audit(string $event, string $type, int $id, $before = null, $after = null, array $details = []): void {
        self::insert('audit_events', [
            'actor_id' => get_current_user_id() ?: null,
            'event_type' => $event,
            'entity_type' => $type,
            'entity_id' => $id,
            'before_json' => $before === null ? null : wp_json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : wp_json_encode($after, JSON_UNESCAPED_UNICODE),
            'details_json' => wp_json_encode($details, JSON_UNESCAPED_UNICODE),
            'recorded_at' => self::now(),
        ]);
    }
}

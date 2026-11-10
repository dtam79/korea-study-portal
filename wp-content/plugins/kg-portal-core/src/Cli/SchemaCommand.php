<?php
/** WP-CLI developer commands. */

defined('ABSPATH') || exit;

final class KGP_Schema_Command {
    /** Create or add missing KGP tables without dropping existing records. */
    public function install(): void {
        $result = KGP_Schema::install();
        if (!$result['ok']) {
            WP_CLI::error('Schema incomplete. Missing tables: ' . implode(', ', $result['missing']));
        }
        WP_CLI::success(
            sprintf('KGP schema %s installed. %d tables verified.', KGP_Schema::VERSION, count(KGP_Schema::names()))
        );
    }

    /** Show expected tables and installed schema version. */
    public function status(): void {
        $result = KGP_Schema::status();
        WP_CLI::log('Expected schema: ' . $result['expected_version']);
        WP_CLI::log('Installed schema: ' . $result['installed_version']);
        WP_CLI::log('Tables found: ' . count($result['existing']) . '/' . count(KGP_Schema::names()));
        if (!$result['ok']) {
            WP_CLI::warning('Missing: ' . implode(', ', $result['missing']));
        } elseif ($result['installed_version'] !== $result['expected_version']) {
            WP_CLI::warning('Version mismatch. Run: wp kgp schema install');
        } else {
            WP_CLI::success('All expected tables are present.');
        }
    }
}

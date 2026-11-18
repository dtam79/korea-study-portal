<?php
/** WordPress read-only data integrity gate for milestone W1-1 / M0. */

defined('ABSPATH') || exit;

final class KGP_Integrity_Command {
    /**
     * Validate stored parent relationships and approved DET field evidence.
     *
     * ## OPTIONS
     *
     * [--max-fields=<n>]
     * : Maximum published fields to validate (default 5000; maximum 20000).
     *
     * ## EXAMPLES
     *
     *     wp --user=1 kgp integrity check
     *     wp --user=1 kgp integrity check --max-fields=10000
     *
     * @subcommand check
     */
    public function check(array $args, array $assoc_args): void {
        if (!current_user_can('manage_options')) {
            WP_CLI::error('Administrator context required. Pass --user=<administrator-id>.');
        }
        $max = $assoc_args['max-fields'] ?? '5000';
        if (!is_string($max) || !ctype_digit($max) || (int)$max < 1 || (int)$max > 20000) {
            WP_CLI::error('--max-fields must be an integer between 1 and 20000.');
        }
        try {
            $report = KGP_Integrity_Audit::scan((int)$max);
        } catch (Throwable $e) {
            WP_CLI::error('Integrity audit could not complete: ' . $e->getMessage());
            return;
        }
        foreach ($report['counts'] as $name => $value) {
            WP_CLI::log($name . ': ' . $value);
        }
        foreach ($report['warnings'] as $message) {
            WP_CLI::warning($message);
        }
        if ($report['issues']) {
            foreach ($report['issues'] as $issue) {
                WP_CLI::warning('[' . $issue['code'] . '] ' . $issue['count'] . ' — ' . $issue['message']);
            }
            WP_CLI::error('Integrity audit failed (' . count($report['issues']) . ' findings). No records were changed.');
        }
        WP_CLI::success('W1-1 integrity audit passed. Read only; no records changed.');
    }
}

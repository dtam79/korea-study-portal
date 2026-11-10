<?php

defined('ABSPATH') || exit;

/** Roles/caps are server-side checks, independent of hiding buttons in the admin UI. */
final class KGP_Access {
    public static function install_roles(): void {
        $roles = [
            'kgp_information_editor' => ['KGP Information Editor', ['kgp_edit_entries']],
            'kgp_reviewer' => ['KGP Reviewer', ['kgp_review_entries', 'kgp_verify_sources']],
            'kgp_inquiry_manager' => ['KGP Inquiry Manager', ['kgp_manage_inquiries']],
            'kgp_portal_manager' => ['KGP Portal Manager', ['kgp_manage_issuers', 'kgp_manage_assignments']],
        ];
        foreach ($roles as $slug => [$label, $caps]) {
            if (!get_role($slug)) {
                add_role($slug, $label, ['read' => true]);
            }
            $role = get_role($slug);
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
        $admin = get_role('administrator');
        if ($admin) {
            foreach (['kgp_edit_entries', 'kgp_review_entries', 'kgp_verify_sources', 'kgp_manage_issuers', 'kgp_manage_assignments', 'kgp_manage_inquiries'] as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    public static function require_cap(string $cap): void {
        if (get_current_user_id() < 1 || !current_user_can($cap)) {
            throw new KGP_Domain_Exception('forbidden', 'You do not have permission: ' . $cap);
        }
    }

    public static function require_edit_for(int $university_id): void {
        global $wpdb;
        self::require_cap('kgp_edit_entries');
        // Site admins may edit all universities, but must pass the same evidence requirements.
        if (current_user_can('manage_options')) {
            return;
        }
        $table = KGP_DB::table('user_assignments');
        $sql = $wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND university_id = %d AND role_code = %s LIMIT 1",
            get_current_user_id(), $university_id, 'editor'
        );
        if (!$wpdb->get_var($sql)) {
            throw new KGP_Domain_Exception('forbidden', 'Editor is not assigned to this university.');
        }
    }

    public static function assign_editor(int $user_id, int $university_id): int {
        self::require_cap('kgp_manage_assignments');
        KGP_DB::require_row('universities', $university_id);
        if (!get_userdata($user_id)) {
            throw new KGP_Domain_Exception('invalid_user', 'User not found.');
        }
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . KGP_DB::table('user_assignments') . ' WHERE user_id = %d AND university_id = %d AND role_code = %s', $user_id, $university_id, 'editor'));
        if ($existing) {
            return (int)$existing;
        }
        return KGP_DB::insert('user_assignments', [
            'user_id' => $user_id,
            'university_id' => $university_id,
            'role_code' => 'editor',
            'assigned_by' => get_current_user_id(),
            'assigned_at' => KGP_DB::now(),
        ]);
    }
}

<?php

defined('ABSPATH') || exit;

final class KGP_Operator_Admin {
    private const PERMS = [
        'edit' => 'kgp_edit_entries',
        'review' => 'kgp_review_entries',
        'source_review' => 'kgp_verify_sources',
        'issuers' => 'kgp_manage_issuers',
        'assign' => 'kgp_manage_assignments',
        'inquiries' => 'kgp_manage_inquiries',
    ];
    private const SCREENS = [
        'kgp-operator' => ['Overview', 'overview'],
        'kgp-operator-entities' => ['University data', 'entities'],
        'kgp-operator-sources' => ['Official sources', 'sources'],
        'kgp-operator-fields' => ['Field drafts', 'fields'],
        'kgp-operator-review' => ['Review & publish', 'review'],
        'kgp-operator-translations' => ['Translations', 'translations'],
        'kgp-operator-imports' => ['CSV imports', 'imports'],
        'kgp-operator-history' => ['History & restore', 'history'],
        'kgp-operator-inquiries' => ['Inquiries', 'inquiries'],
        'kgp-operator-settings' => ['Operating settings', 'settings'],
    ];

    public static function ready(): bool {
        return defined('KGP_CORE_VERSION') && version_compare(KGP_CORE_VERSION, '0.4.0', '>=')
            && class_exists('KGP_Field_Catalog') && class_exists('KGP_Identity_Repository')
            && class_exists('KGP_Validator') && class_exists('KGP_DB');
    }
    public static function permitted(): bool {
        if (!is_user_logged_in()) return false;
        foreach (self::PERMS as $cap) {
            if (current_user_can($cap)) return true;
        }
        return false;
    }
    public static function register_menu(): void {
        if (!self::permitted()) return;
        $menu_cap = self::menu_capability();
        add_menu_page('KGP Operations', 'Study Portal', $menu_cap, 'kgp-operator', [self::class, 'render'], 'dashicons-welcome-learn-more', 30);
        foreach (self::SCREENS as $slug => [$title, $view]) {
            if ($slug === 'kgp-operator') {
                add_submenu_page('kgp-operator', $title, $title, $menu_cap, $slug, [self::class, 'render']);
            } elseif ($view === 'translations' && !(current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries'))) {
                continue;
            } elseif ($view === 'imports' && !current_user_can('kgp_edit_entries')) {
                continue;
            } elseif ($view === 'history' && !(current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries'))) {
                continue;
            } elseif ($view === 'inquiries' && !current_user_can('kgp_manage_inquiries')) {
                continue;
            } elseif ($view === 'settings' && !current_user_can('kgp_manage_assignments')) {
                continue;
            } elseif ($view === 'review' && !current_user_can('kgp_review_entries')) {
                continue;
            } elseif ($view === 'sources' && !(current_user_can('kgp_edit_entries') || current_user_can('kgp_verify_sources') || current_user_can('kgp_manage_issuers'))) {
                continue;
            } elseif ($view === 'fields' && !(current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries'))) {
                continue;
            } elseif ($view === 'entities' && !(current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries') || current_user_can('kgp_manage_assignments'))) {
                continue;
            } else {
                add_submenu_page('kgp-operator', $title, $title, $menu_cap, $slug, [self::class, 'render']);
            }
        }
    }
    private static function menu_capability(): string {
        foreach (self::PERMS as $cap) if (current_user_can($cap)) return $cap;
        return 'do_not_allow';
    }
    public static function enqueue(string $hook): void {
        $page = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if (!isset(self::SCREENS[$page]) || !self::permitted()) return;
        wp_enqueue_style('kgp-operator', plugins_url('assets/admin.css', KGP_ADMIN_DIR . 'kg-portal-admin.php'), [], KGP_ADMIN_VERSION);
        wp_enqueue_script('kgp-operator', plugins_url('assets/admin.js', KGP_ADMIN_DIR . 'kg-portal-admin.php'), [], KGP_ADMIN_VERSION, true);
        if (in_array(self::SCREENS[$page][1], ['translations','imports','history'], true)) {
            wp_enqueue_script('kgp-operator-workflows', plugins_url('assets/workflows.js', KGP_ADMIN_DIR . 'kg-portal-admin.php'), [], KGP_ADMIN_VERSION, true);
        }
        if (in_array(self::SCREENS[$page][1], ['inquiries','settings'], true)) {
            wp_enqueue_script('kgp-operator-inquiries', plugins_url('assets/inquiries.js', KGP_ADMIN_DIR . 'kg-portal-admin.php'), ['kgp-operator'], KGP_ADMIN_VERSION, true);
        }
        wp_add_inline_script('kgp-operator', 'window.KGP_ADMIN = ' . wp_json_encode([
            'apiRoot' => esc_url_raw(rest_url('kgp/v1/')),
            'operatorRoot' => esc_url_raw(rest_url('kgp-operator/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'page' => self::SCREENS[$page][1],
            'adminBase' => admin_url('admin.php?page='),
            'permissions' => array_map('current_user_can', self::PERMS),
            'userId' => get_current_user_id(),
            'coreReady' => self::ready(),
            'exportUrl' => wp_nonce_url(admin_url('admin-post.php?action=kgpa_export_inquiries'),'kgpa_inquiry_export'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';', 'before');
    }
    public static function render(): void {
        if (!self::permitted()) wp_die(esc_html__('Access denied.', 'kg-portal-admin'), '', ['response' => 403]);
        $page = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        $title = self::SCREENS[$page][0] ?? 'Operations';
        echo '<div class="wrap kgp-operator-wrap"><h1>Korea Study Portal <span class="kgp-subtitle">' . esc_html($title) . '</span></h1>';
        if (!self::ready()) {
            echo '<div class="notice notice-error"><p>Activate KG Portal Core v0.4.0 or newer first. This extension does not replace the Core plugin.</p></div></div>';
            return;
        }
        echo '<div class="kgp-operator-layout"><div id="kgp-operator-root" role="region" aria-label="Study Portal administration"><p class="kgp-loading" role="status">Loading the operator interface…</p></div></div>';
        echo '<noscript><p>JavaScript is required for the editor. No changes have been made.</p></noscript></div>';
    }
}

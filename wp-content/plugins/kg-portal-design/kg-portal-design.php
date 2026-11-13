<?php
/**
 * Plugin Name: KG Portal Design System
 * Description: Unified presentation system for KG Portal public and admin screens. Searchable relationships and refreshed public/admin interfaces.
 * Version: 2.1.2
 * Requires PHP: 8.1
 * Text Domain: kg-portal-design
 */
defined('ABSPATH') || exit;

define('KGDS_VERSION','2.1.2');
define('KGDS_DIR',plugin_dir_path(__FILE__));

/** The source templates are owned by independent plugins; design assets run afterward. */
final class KGDS_Assets {
    private static function public_route(): bool {
        if (is_admin()) return false;
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path)) return false;
        // Only the portal's public route tree; no takeover of unrelated Kadence pages.
        return preg_match('~^/(ko|en)(?:/|$)~',$path) === 1;
    }
    public static function public_assets(): void {
        if (!self::public_route()) return;
        wp_enqueue_style('kgds-public',plugins_url('assets/public.css',__FILE__),[],KGDS_VERSION);
        wp_enqueue_style('kgds-refresh',plugins_url('assets/refresh.css',__FILE__),['kgds-public'],KGDS_VERSION);
        wp_enqueue_script('kgds-public',plugins_url('assets/public.js',__FILE__),[],KGDS_VERSION,['strategy'=>'defer','in_footer'=>true]);
        $path=wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (is_string($path) && preg_match('~^/(ko|en)/(?:universities|admissions)/?$~',$path)) {
            wp_enqueue_script('kgds-filter-controls',plugins_url('assets/filter-controls.js',__FILE__),[],KGDS_VERSION,['strategy'=>'defer','in_footer'=>true]);
        }
    }
    private static function operator_page(): bool {
        if (!is_admin()) return false;
        $screen=function_exists('get_current_screen')?get_current_screen():null;
        if ($screen && isset($screen->post_type) && $screen->post_type === 'kgpc_article') return true;
        $page=isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        foreach (['kgp-operator','kgpc-','kgpo-'] as $prefix) {
            if (str_starts_with($page,$prefix)) return true;
        }
        return false;
    }
    /** Target only admission-list compact patterns, not every <details> on the site. */
    public static function public_class(array $classes): array {
        if (self::public_route()) {
            $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (is_string($path) && preg_match('~^/(ko|en)/(?:universities|admissions)/?$~', $path)) {
                $classes[]='kgds-filter-route';
                if (preg_match('~^/(ko|en)/admissions/?$~', $path)) $classes[]='kgds-route-admissions';
            }
        }
        return $classes;
    }
    public static function admin_class(string $classes): string {
        return self::operator_page() ? $classes . ' kgds-ui-admin' : $classes;
    }
    public static function admin_assets(): void {
        if (!self::operator_page()) return;
        wp_enqueue_style('kgds-admin',plugins_url('assets/admin.css',__FILE__),[],KGDS_VERSION);
        wp_enqueue_style('kgds-workspace',plugins_url('assets/workspace.css',__FILE__),['kgds-admin'],KGDS_VERSION);
        wp_enqueue_script('kgds-relations',plugins_url('assets/relations.js',__FILE__),[],KGDS_VERSION,true);
        wp_add_inline_script('kgds-relations','window.KGDS_LOOKUP='.wp_json_encode(['url'=>rest_url('kgds/v1/lookup'),'nonce'=>wp_create_nonce('wp_rest')]).';','before');
        wp_enqueue_script('kgds-admin',plugins_url('assets/admin.js',__FILE__),[],KGDS_VERSION,['strategy'=>'defer','in_footer'=>true]);
    }
}
add_action('wp_enqueue_scripts',['KGDS_Assets','public_assets'],999);
add_action('admin_enqueue_scripts',['KGDS_Assets','admin_assets'],999);
add_filter('admin_body_class',['KGDS_Assets','admin_class']);
add_filter('body_class',['KGDS_Assets','public_class']);

require_once KGDS_DIR.'includes/class-lookup.php';

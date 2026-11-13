<?php
/**
 * Plugin Name: KG Portal Operations
 * Description: Read-only source change monitoring, reviewer queue, safe sitemap and cache guard.
 * Version: 1.0.0
 * Requires PHP: 8.3
 * Requires Plugins: kg-portal-core
 * Text Domain: kg-portal-ops
 */
defined('ABSPATH') || exit;
define('KGPO_VERSION','1.0.0');
define('KGPO_DIR',plugin_dir_path(__FILE__));
require_once KGPO_DIR.'inc/class-schema.php';
require_once KGPO_DIR.'inc/class-policy.php';
require_once KGPO_DIR.'inc/class-monitor.php';
require_once KGPO_DIR.'inc/class-admin.php';
require_once KGPO_DIR.'inc/class-rest.php';
require_once KGPO_DIR.'inc/class-seo.php';
require_once KGPO_DIR.'inc/class-cli.php';
register_activation_hook(__FILE__,['KGPO_Schema','activate']);
register_deactivation_hook(__FILE__,['KGPO_Schema','deactivate']);
add_action('admin_init',['KGPO_Schema','maybe_upgrade']);
add_action('admin_menu',['KGPO_Admin','menu']);
add_action('admin_enqueue_scripts',['KGPO_Admin','enqueue']);
add_action('rest_api_init',['KGPO_REST','register']);
add_action('kgpo_scan_due',['KGPO_Monitor','scheduled']);
add_action('init',['KGPO_SEO','routes'],30);
add_filter('query_vars',['KGPO_SEO','vars']);
add_action('template_redirect',['KGPO_SEO','serve_sitemap'],0);
add_action('template_redirect',['KGPO_SEO','cache_headers'],2);
add_action('wp_head',['KGPO_SEO','head'],2);
add_filter('robots_txt',['KGPO_SEO','robots'],10,2);
add_filter('wp_robots',['KGPO_SEO','robot_directives'],20);
add_filter('wp_sitemaps_enabled',['KGPO_SEO','core_sitemaps'],20);
add_filter('pre_handle_404',['KGPO_SEO','no_404'],15);
if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('kgpo','KGPO_CLI');

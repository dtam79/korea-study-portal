<?php
/**
 * Plugin Name: KG Portal Admin
 * Description: Private operator dashboard for KG Portal Core. Draft entry and evidence review.
 * Version: 0.8.0
 * Requires PHP: 8.3
 * Requires Plugins: kg-portal-core
 * Text Domain: kg-portal-admin
 */
defined('ABSPATH') || exit;

define('KGP_ADMIN_VERSION', '0.8.0');
define('KGP_ADMIN_DIR', plugin_dir_path(__FILE__));

require_once KGP_ADMIN_DIR . 'inc/class-admin.php';
require_once KGP_ADMIN_DIR . 'inc/class-rest.php';
add_action('admin_menu', ['KGP_Operator_Admin', 'register_menu'], 25);
add_action('admin_enqueue_scripts', ['KGP_Operator_Admin', 'enqueue']);
add_action('rest_api_init', ['KGP_Operator_REST', 'register']);

require_once KGP_ADMIN_DIR . 'inc/workflow/class-schema.php';
require_once KGP_ADMIN_DIR . 'inc/workflow/class-translations.php';
require_once KGP_ADMIN_DIR . 'inc/workflow/class-csv.php';
require_once KGP_ADMIN_DIR . 'inc/workflow/class-history.php';
require_once KGP_ADMIN_DIR . 'inc/workflow/class-routes.php';
register_activation_hook(__FILE__, ['KGP_Operator_Workflow_Schema', 'install']);
add_action('admin_init', static function () {
    if (KGP_Operator_Admin::ready() && current_user_can('manage_options')) KGP_Operator_Workflow_Schema::ensure();
});
add_action('rest_api_init', ['KGP_Operator_Workflow_Routes','register']);

// v0.8.0: A08 inquiry workflows, A09 configured retention/consent, P08/P09 public contact.
require_once KGP_ADMIN_DIR . 'inc/inquiry/class-settings.php';
require_once KGP_ADMIN_DIR . 'inc/inquiry/class-schema.php';
require_once KGP_ADMIN_DIR . 'inc/inquiry/class-inquiries.php';
require_once KGP_ADMIN_DIR . 'inc/inquiry/class-routes.php';
require_once KGP_ADMIN_DIR . 'inc/inquiry/class-export.php';
require_once KGP_ADMIN_DIR . 'inc/inquiry/class-public.php';
register_activation_hook(__FILE__, ['KGP_Operator_Inquiry_Schema', 'install']);
register_deactivation_hook(__FILE__, ['KGP_Operator_Inquiry_Schema', 'deactivate']);
add_action('admin_init', static function () {
    if (KGP_Operator_Admin::ready() && current_user_can('manage_options')) KGP_Operator_Inquiry_Schema::ensure();
});
add_action('rest_api_init',['KGP_Operator_Inquiry_Routes','register']);
add_action('rest_api_init',['KGP_Operator_Contact_Pages','register_public_rest']);
add_action('init',['KGP_Operator_Contact_Pages','routes'],100);
add_filter('query_vars',['KGP_Operator_Contact_Pages','vars']);
add_filter('pre_handle_404',['KGP_Operator_Contact_Pages','status']);
add_filter('redirect_canonical',['KGP_Operator_Contact_Pages','no_redirect']);
add_filter('template_include',['KGP_Operator_Contact_Pages','template'],120);
add_action('wp_enqueue_scripts',['KGP_Operator_Contact_Pages','enqueue'],110);
add_action('template_redirect',['KGP_Operator_Contact_Pages','no_cache'],1);
add_action('admin_post_nopriv_kgpa_submit_inquiry',['KGP_Operator_Contact_Pages','submit']);
add_action('admin_post_kgpa_submit_inquiry',['KGP_Operator_Contact_Pages','submit']);
add_action('admin_post_kgpa_export_inquiries',['KGP_Operator_Inquiry_Export','export']);
add_action('kgpa_inquiry_retention_cleanup',['KGP_Operator_Inquiries','delete_expired']);

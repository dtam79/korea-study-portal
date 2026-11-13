<?php
/**
 * Plugin Name: KG Portal Content
 * Description: Evidence-reviewed bilingual study guides, FAQ and operator-approved policy pages.
 * Version: 0.9.2
 * Requires PHP: 8.3
 * Text Domain: kg-portal-content
 */
defined('ABSPATH') || exit;
define('KGPC_VERSION','0.9.2');
define('KGPC_DIR', plugin_dir_path(__FILE__));
require_once KGPC_DIR.'includes/class-content.php';
require_once KGPC_DIR.'includes/class-review.php';
require_once KGPC_DIR.'includes/class-public.php';
register_activation_hook(__FILE__, ['KGPC_Content','activate']);
add_action('init',['KGPC_Content','register'],5);
add_action('admin_menu',['KGPC_Content','admin_menu'],15);
add_action('add_meta_boxes_kgpc_article',['KGPC_Content','meta_boxes']);
add_action('save_post_kgpc_article',['KGPC_Content','save_post'],10,3);
add_filter('wp_insert_post_data',['KGPC_Content','prevent_native_publication'],10,2);
add_action('admin_post_kgpc_save_metadata',['KGPC_Content','metadata_save']);
add_action('admin_post_kgpc_submit',['KGPC_Review','submit']);
add_action('admin_post_kgpc_decide',['KGPC_Review','decide']);
add_action('admin_post_kgpc_unpublish',['KGPC_Review','unpublish']);
add_action('init',['KGPC_Public','routes'],999);
add_filter('query_vars',['KGPC_Public','query_vars']);
add_filter('template_include',['KGPC_Public','template'],250);
add_filter('redirect_canonical',['KGPC_Public','disable_canonical'],20);
add_filter('pre_handle_404',['KGPC_Public','disable_404'],20);
add_filter('pre_get_document_title',['KGPC_Public','page_title'],15);
add_action('template_redirect',['KGPC_Public','nocache'],1);
add_action('wp_enqueue_scripts',['KGPC_Public','assets'],105);

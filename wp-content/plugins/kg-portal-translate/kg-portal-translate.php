<?php
/**
 * Plugin Name: KG Portal Browser Language
 * Description: Server-mediated Gemini display translation of already-published public pages. Approved KO/EN records are never changed.
 * Version: 0.1.0
 * Requires PHP: 8.3
 * Text Domain: kg-portal-translate
 */
defined('ABSPATH') || exit;
const KGPT_VERSION = '0.1.0';
const KGPT_DIR = __DIR__ . '/';
require_once KGPT_DIR . 'inc/class-gemini.php';
require_once KGPT_DIR . 'inc/class-translation.php';
add_action('template_redirect', ['KGPT_Translation', 'capture'], 300);
add_action('wp_enqueue_scripts', ['KGPT_Translation', 'assets'], 140);
add_action('rest_api_init', ['KGPT_Translation', 'register_rest']);

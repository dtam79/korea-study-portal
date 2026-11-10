<?php
/**
 * Korea Study Portal FE 0.5.0.
 * Public pages use a Kadence child theme, with v0.4.0 KGP REST routes as the sole data source.
 */
defined('ABSPATH') || exit;
define('KGP_FE_VERSION', '0.5.0');
define('KGP_FE_DIR', get_stylesheet_directory());
require_once KGP_FE_DIR . '/inc/routes.php';
require_once KGP_FE_DIR . '/inc/view.php';

add_action('wp_enqueue_scripts', static function (): void {
    if (!kgp_fe_is_public_route()) return;
    wp_enqueue_style('kgp-fe-site', get_stylesheet_directory_uri() . '/assets/css/site.css', [], KGP_FE_VERSION);
    wp_enqueue_script('kgp-fe-site', get_stylesheet_directory_uri() . '/assets/js/site.js', [], KGP_FE_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
}, 100);

add_filter('pre_get_document_title', static function ($title) {
    if (!kgp_fe_is_public_route()) return $title;
    $lang = kgp_fe_lang();
    $screen = get_query_var('kgp_screen');
    $parts = [
        'home' => 'Korea Study Portal',
        'search' => kgp_fe_t('nav.search', 'Find universities', $lang),
        'university' => kgp_fe_t('detail.title', 'University details', $lang),
        'admissions' => kgp_fe_t('nav.admissions', 'Admissions', $lang),
        'guides' => kgp_fe_t('nav.guides', 'Study guides', $lang),
        'guide' => kgp_fe_t('nav.guides', 'Study guides', $lang),
        'faq' => 'FAQ',
        'contact' => kgp_fe_t('nav.contact', 'Contact', $lang),
    ];
    return ($parts[$screen] ?? 'Korea Study Portal') . ' | Korea Study Portal';
});

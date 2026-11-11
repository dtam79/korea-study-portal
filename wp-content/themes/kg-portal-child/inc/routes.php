<?php
defined('ABSPATH') || exit;

/** URLs remain /ko/... and /en/... without creating placeholder WP pages. */
function kgp_fe_register_routes(): void {
    $rules = [
        '^(ko|en)/?$' => 'index.php?kgp_screen=home&kgp_lang=$matches[1]',
        '^(ko|en)/universities/?$' => 'index.php?kgp_screen=search&kgp_lang=$matches[1]',
        '^(ko|en)/universities/([A-Za-z0-9_-]{2,64})/?$' => 'index.php?kgp_screen=university&kgp_lang=$matches[1]&kgp_uni=$matches[2]',
        '^(ko|en)/admissions/?$' => 'index.php?kgp_screen=admissions&kgp_lang=$matches[1]',
        '^(ko|en)/guides/?$' => 'index.php?kgp_screen=guides&kgp_lang=$matches[1]',
        '^(ko|en)/guides/([a-z0-9-]+)/?$' => 'index.php?kgp_screen=guide&kgp_lang=$matches[1]&kgp_guide=$matches[2]',
        '^(ko|en)/faq/?$' => 'index.php?kgp_screen=faq&kgp_lang=$matches[1]',
    ];
    foreach ($rules as $pattern => $target) add_rewrite_rule($pattern, $target, 'top');
}
add_action('init', 'kgp_fe_register_routes');
add_filter('query_vars', static function (array $vars): array {
    return array_merge($vars, ['kgp_screen', 'kgp_lang', 'kgp_uni', 'kgp_guide']);
});
add_filter('pre_handle_404', static function ($preempt) {
    if (get_query_var('kgp_screen')) return true;
    return $preempt;
}, 10, 1);
add_filter('redirect_canonical', static function ($redirect) {
    return kgp_fe_is_public_route() ? false : $redirect;
});
add_filter('template_include', static function (string $template): string {
    if (!kgp_fe_is_public_route()) return $template;
    return KGP_FE_DIR . '/inc/public-template.php';
}, 99);
add_action('after_switch_theme', static function (): void {
    kgp_fe_register_routes();
    flush_rewrite_rules();
});
function kgp_fe_is_public_route(): bool {
    return (string)get_query_var('kgp_screen') !== '';
}
function kgp_fe_lang(): string {
    return get_query_var('kgp_lang') === 'en' ? 'en' : 'ko';
}
function kgp_fe_url(string $lang, string $path = ''): string {
    return home_url('/' . ($lang === 'en' ? 'en' : 'ko') . '/' . ltrim($path, '/'));
}
function kgp_fe_switch_url(string $lang): string {
    $screen = (string)get_query_var('kgp_screen');
    $route = match ($screen) {
        'search' => 'universities/', 'university' => 'universities/' . (string)get_query_var('kgp_uni') . '/',
        'admissions' => 'admissions/', 'guides' => 'guides/',
        'guide' => 'guides/' . (string)get_query_var('kgp_guide') . '/',
        'faq' => 'faq/', 'contact' => 'contact/', default => '',
    };
    $params = kgp_fe_filter_params($_GET, true);
    if ($screen === 'university' && isset($_GET['offering']) && is_scalar($_GET['offering'])) {
        $id = sanitize_text_field(wp_unslash((string)$_GET['offering']));
        if (preg_match('/^[A-Za-z0-9_-]{2,64}$/D', $id)) $params['offering'] = $id;
    }
    return add_query_arg($params, kgp_fe_url($lang, $route));
}

/** New installations open at the localized public home, never a WP demo blog. */
add_action('template_redirect', static function (): void {
    if (is_admin() || kgp_fe_is_public_route()) {
        return;
    }

    // 문의 페이지는 리다이렉션하지 않음
    if (
        get_query_var('kgpa_contact') ||
        get_query_var('kgpa_contact_done')
    ) {
        return;
    }

    // 실제 사이트 최상위 경로에 접속했을 때만 /ko/로 이동
    $request_path = wp_parse_url(
        $_SERVER['REQUEST_URI'] ?? '/',
        PHP_URL_PATH
    );

    $home_path = wp_parse_url(
        home_url('/'),
        PHP_URL_PATH
    );

    if (
        !is_string($request_path) ||
        !is_string($home_path) ||
        trailingslashit($request_path) !== trailingslashit($home_path)
    ) {
        return;
    }

    wp_safe_redirect(kgp_fe_url('ko'), 302);
    exit;
}, 20);
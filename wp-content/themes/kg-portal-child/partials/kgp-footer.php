<?php
/** Shared public footer for P01-P10. Do not duplicate this markup in page templates. */
defined('ABSPATH') || exit;
$kgp_footer_lang = isset($lang) && $lang === 'en' ? 'en' : 'ko';
$kgp_footer_links = [
    'policy' => home_url('/' . $kgp_footer_lang . '/policy/'),
    'contact' => home_url('/' . $kgp_footer_lang . '/contact/'),
];
?>
<footer class="kgp-footer" role="contentinfo">
    <p class="kgp-footer-disclaimer"><?php echo esc_html($kgp_footer_lang === 'ko'
        ? '본 포털은 공식 공개 자료를 안내하며 실제 지원은 대학 공식 홈페이지에서 진행합니다.'
        : 'This portal provides information from publicly available official sources. Applications take place on official university websites.'); ?></p>
    <nav class="kgp-footer-links" aria-label="<?php echo esc_attr($kgp_footer_lang === 'ko' ? '하단 메뉴' : 'Footer navigation'); ?>">
        <a href="<?php echo esc_url($kgp_footer_links['policy']); ?>"><?php echo esc_html($kgp_footer_lang === 'ko' ? '정책과 운영 안내' : 'Policies and operations'); ?></a>
        <span aria-hidden="true">·</span>
        <a href="<?php echo esc_url($kgp_footer_links['contact']); ?>"><?php echo esc_html($kgp_footer_lang === 'ko' ? '문의하기' : 'Contact'); ?></a>
    </nav>
</footer>

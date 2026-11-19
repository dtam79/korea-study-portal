<?php
/** Shared C01 public header, as required by BG/WF/UI v1.2. */
defined('ABSPATH') || exit;
$kgp_header_lang = isset($kgp_header_lang) && $kgp_header_lang === 'en' ? 'en' : 'ko';
$kgp_header_active = (string)($kgp_header_active ?? '');
$kgp_header_switch = (array)($kgp_header_switch ?? []);
$kgp_header_home = home_url('/' . $kgp_header_lang . '/');
$kgp_header_menu = [
    ['home','', '홈','Home'],
    ['search','universities/','대학 찾기','Find universities'],
    ['admissions','admissions/','모집 일정','Admissions'],
    ['guides','guides/','한국 유학 가이드','Study guides'],
    ['faq','faq/','FAQ','FAQ'],
    ['contact','contact/','문의하기','Contact'],
];
?>
<header class="kgp-site-header">
  <div class="kgp-header-line">
    <a class="kgp-brand" href="<?php echo esc_url($kgp_header_home); ?>">
      <span class="kgp-brand-copy"><strong><?php echo esc_html($kgp_header_lang === 'ko' ? '한국 대학 유학 정보 포털' : 'Korean University Study Information Portal'); ?></strong><small><?php echo esc_html($kgp_header_lang === 'ko' ? '공식 대학 정보 안내' : 'Verified official university information'); ?></small></span>
    </a>
    <div class="kgp-header-actions">
      <nav class="kgp-language" aria-label="<?php echo esc_attr($kgp_header_lang === 'ko' ? '언어 선택' : 'Language'); ?>">
        <?php foreach (['ko'=>'한국어','en'=>'EN'] as $code=>$text): ?>
          <a href="<?php echo esc_url((string)($kgp_header_switch[$code] ?? home_url('/'.$code.'/'))); ?>" <?php if ($code === $kgp_header_lang) echo 'aria-current="true"'; ?>><?php echo esc_html($text); ?></a>
        <?php endforeach; ?>
      </nav>
      <details class="kgp-menu">
        <summary aria-label="<?php echo esc_attr($kgp_header_lang === 'ko' ? '메뉴 열기' : 'Open navigation'); ?>"><span aria-hidden="true" class="kgp-menu-icon">☰</span><span><?php echo esc_html($kgp_header_lang === 'ko' ? '메뉴' : 'Menu'); ?></span></summary>
        <nav class="kgp-nav" aria-label="<?php echo esc_attr($kgp_header_lang === 'ko' ? '주 메뉴' : 'Main navigation'); ?>">
          <?php foreach ($kgp_header_menu as [$name,$path,$ko,$en]): ?>
            <a href="<?php echo esc_url(home_url('/'.$kgp_header_lang.'/'.$path)); ?>" <?php if ($kgp_header_active===$name) echo 'aria-current="page"'; ?>><?php echo esc_html($kgp_header_lang === 'ko' ? $ko : $en); ?></a>
          <?php endforeach; ?>
        </nav>
      </details>
    </div>
  </div>
</header>

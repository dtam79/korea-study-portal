<?php
/** Public P08/P09 inquiry flow. No preview or internal record ever rendered here. */
defined('ABSPATH') || exit;
$lang=KGP_Operator_Contact_Pages::language();
$t=static fn(string $key)=>KGP_Operator_Contact_Pages::text($key,$lang);
$settings=KGP_Operator_Inquiry_Settings::get();
$is_done=(bool)get_query_var('kgpa_contact_done');
$mode=isset($_GET['type'])&&$_GET['type']==='university'?'university':'operator';
$selected_uni=isset($_GET['university'])&&is_scalar($_GET['university'])?sanitize_text_field(wp_unslash((string)$_GET['university'])):'';
$unis=KGP_Operator_Contact_Pages::approved_universities();
$ids=array_column($unis,'id');
if (!in_array($selected_uni,$ids,true)) $selected_uni='';
$university=$mode==='university'&&$selected_uni?KGP_Operator_Contact_Pages::university_contact($selected_uni,$lang):null;
$programs=(!$is_done&&$mode==='operator'&&$selected_uni)?KGP_Public_Catalog::university($selected_uni,$lang):null;
$receipt=isset($_GET['receipt'])&&is_scalar($_GET['receipt'])?(string)$_GET['receipt']:'';
$receipt=preg_match('/^KGP-[A-F0-9]{24}$/D',$receipt)?$receipt:'';
$status_error=isset($_GET['error'])&&is_scalar($_GET['error'])?sanitize_key((string)$_GET['error']):'';
status_header($is_done&&$receipt===''?404:200);
?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>">
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class('kgpa-contact-page'); ?>>
<?php wp_body_open(); ?>
<a href="#kgpa-contact-main" class="kgpa-skip">Skip to content</a>
<div class="kgpa-public-shell">
<header class="kgpa-public-header"><a class="kgpa-brand" href="<?php echo esc_url(home_url('/'.$lang.'/')); ?>">Korea Study Portal</a>
<nav class="kgpa-public-lang"><a href="<?php echo esc_url(KGP_Operator_Contact_Pages::url('ko')); ?>" <?php echo $lang==='ko'?'aria-current="page"':''; ?>>한국어</a> / <a href="<?php echo esc_url(KGP_Operator_Contact_Pages::url('en')); ?>" <?php echo $lang==='en'?'aria-current="page"':''; ?>>EN</a></nav></header>
<main id="kgpa-contact-main" class="kgpa-public-main">
<?php if ($is_done): ?>
  <?php if ($receipt): ?>
    <div class="kgpa-confirm-icon" aria-hidden="true">✓</div><h1><?php echo esc_html($t('done')); ?></h1>
    <div class="kgpa-receipt"><span><?php echo esc_html($t('receipt')); ?></span><strong><?php echo esc_html($receipt); ?></strong></div>
    <p><?php echo esc_html($t('keep')); ?></p>
  <?php else: ?><h1>Page not found</h1><?php endif; ?>
  <a class="kgpa-primary-link" href="<?php echo esc_url(home_url('/'.$lang.'/')); ?>"><?php echo esc_html($t('home')); ?></a>
<?php else: ?>
  <h1><?php echo esc_html($t('title')); ?></h1><p class="kgpa-intro"><?php echo esc_html($t('intro')); ?></p>
  <nav class="kgpa-tabs" aria-label="Inquiry recipient">
    <a href="<?php echo esc_url(KGP_Operator_Contact_Pages::url($lang)); ?>" <?php echo $mode==='operator'?'aria-current="page"':''; ?>><?php echo esc_html($t('operator')); ?></a>
    <a href="<?php echo esc_url(add_query_arg('type','university',KGP_Operator_Contact_Pages::url($lang))); ?>" <?php echo $mode==='university'?'aria-current="page"':''; ?>><?php echo esc_html($t('university')); ?></a>
  </nav>
  <?php if ($mode==='university'): ?>
    <form class="kgpa-public-form" action="<?php echo esc_url(KGP_Operator_Contact_Pages::url($lang)); ?>" method="get">
      <input type="hidden" name="type" value="university">
      <label for="kgpa-university-select"><?php echo esc_html($t('choose')); ?></label>
      <select id="kgpa-university-select" name="university" required><option value=""><?php echo esc_html($t('select')); ?></option>
      <?php foreach ($unis as $u): ?><option value="<?php echo esc_attr($u['id']); ?>" <?php selected($selected_uni,$u['id']); ?>><?php echo esc_html($lang==='en'&&$u['name_en']?$u['name_en']:$u['name_ko']); ?></option><?php endforeach; ?></select>
      <button type="submit"><?php echo esc_html($t('choose_action')); ?></button>
    </form>
    <?php if ($university): ?>
      <section class="kgpa-university-contact"><h2><?php echo esc_html($university['identity']['name']); ?></h2>
        <?php if ($university['links']): ?><ul>
          <?php foreach ($university['links'] as $link): ?><li><a href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['label']); ?> ↗</a></li><?php endforeach; ?></ul>
        <?php else: ?><p><?php echo esc_html($t('no_contact')); ?></p><?php endif; ?>
        <?php if (!empty($university['identity']['official_website'])): ?><p><a href="<?php echo esc_url($university['identity']['official_website']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($t('official')); ?> ↗</a></p><?php endif; ?>
        <p class="kgpa-note"><?php echo esc_html($t('external')); ?></p>
      </section>
    <?php endif; ?>
  <?php elseif (!KGP_Operator_Admin::ready() || !KGP_Operator_Inquiry_Settings::ready($settings)): ?>
    <p role="status" class="kgpa-alert"><?php echo esc_html($t('closed')); ?></p>
  <?php else: ?>
    <?php if ($status_error): ?><p role="alert" class="kgpa-alert"><?php echo esc_html(KGP_Operator_Contact_Pages::error_message($status_error,$lang)); ?></p><?php endif; ?>
    <form id="kgpa-inquiry-form" class="kgpa-public-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kgpa_submit_inquiry"><input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
      <input type="hidden" name="form_token" value="<?php echo esc_attr(KGP_Operator_Contact_Pages::token()); ?>">
      <input type="hidden" name="policy_version" value="<?php echo esc_attr($settings['policy_version']); ?>">
      <?php wp_nonce_field('kgpa_submit_inquiry','kgpa_nonce'); ?>
      <div class="kgpa-honeypot" aria-hidden="true"><label for="kgpa-site-fax">Leave this blank</label><input id="kgpa-site-fax" name="website_fax" autocomplete="off" type="text" tabindex="-1"></div>
      <p class="kgpa-recipient"><?php echo esc_html($t('recipient')); ?></p>
      <label for="kgpa-email"><?php echo esc_html($t('email')); ?> <span aria-hidden="true">*</span></label>
      <input id="kgpa-email" name="email" type="email" maxlength="254" autocomplete="email" required>
      <label for="kgpa-language"><?php echo esc_html($t('language')); ?> <span aria-hidden="true">*</span></label>
      <select name="inquiry_locale" id="kgpa-language" required><option value="ko" <?php selected($lang,'ko'); ?>>한국어</option><option value="en" <?php selected($lang,'en'); ?>>English</option></select>
      <label for="kgpa-preferred-uni"><?php echo esc_html($t('choose')); ?></label>
      <select name="university_public_id" id="kgpa-preferred-uni"><option value=""><?php echo esc_html($t('select')); ?></option>
        <?php foreach ($unis as $u): ?><option value="<?php echo esc_attr($u['id']); ?>" <?php selected($selected_uni,$u['id']); ?>><?php echo esc_html($lang==='en'&&$u['name_en']?$u['name_en']:$u['name_ko']); ?></option><?php endforeach; ?>
      </select>
      <label for="kgpa-offering"><?php echo esc_html($t('program')); ?></label>
      <select name="offering_public_id" id="kgpa-offering"><option value=""><?php echo esc_html($t('select')); ?></option>
        <?php foreach (($programs['offerings']??[]) as $o): ?><option value="<?php echo esc_attr($o['offering_id']); ?>"><?php echo esc_html(trim(($o['program_name']??'').' · '.($o['academic_year']??'').' '.($o['academic_term']??''))); ?></option><?php endforeach; ?>
      </select>
      <p class="kgpa-help"><?php echo $lang==='en'?'To choose a program, select a university and load its published offerings.':'전공을 선택하려면 대학을 선택한 뒤 아래 버튼을 눌러 주세요.'; ?></p>
      <button type="button" id="kgpa-load-university" data-endpoint="<?php echo esc_url(rest_url('kgpa/v1/offerings')); ?>" class="kgpa-secondary"><?php echo $lang==='en'?'Load programs':'전공 목록 불러오기'; ?></button>
      <label for="kgpa-preferred-intake"><?php echo esc_html($t('intake')); ?></label>
      <input type="text" maxlength="100" name="preferred_intake" id="kgpa-preferred-intake" placeholder="2027 Fall">
      <label for="kgpa-message"><?php echo esc_html($t('message')); ?> <span aria-hidden="true">*</span></label>
      <textarea name="message_body" id="kgpa-message" required minlength="10" maxlength="2000" rows="7" aria-describedby="kgpa-sensitive-warning"></textarea>
      <p class="kgpa-help" id="kgpa-sensitive-warning"><?php echo esc_html($t('no_docs')); ?></p>
      <section class="kgpa-privacy" aria-labelledby="kgpa-privacy-title"><h2 id="kgpa-privacy-title"><?php echo esc_html($t('consent')); ?></h2>
        <dl><dt><?php echo esc_html($t('recipient')); ?></dt><dd>Korea Study Portal</dd>
          <dt><?php echo esc_html($t('purpose')); ?></dt><dd><?php echo nl2br(esc_html($lang==='en'?$settings['purpose_en']:$settings['purpose_ko'])); ?></dd>
          <dt><?php echo esc_html($t('retention')); ?></dt><dd><?php echo esc_html((string)$settings['retention_days']); ?> <?php echo $lang==='en'?'days':'일'; ?></dd>
          <dt><?php echo esc_html($t('contact')); ?></dt><dd><?php echo esc_html($settings['deletion_contact']); ?></dd>
          <dt><?php echo esc_html($t('privacy_ver')); ?></dt><dd><?php echo esc_html($settings['policy_version']); ?></dd></dl>
        <p><?php echo nl2br(esc_html($lang==='en'?$settings['notice_en']:$settings['notice_ko'])); ?></p>
        <label class="kgpa-check"><input type="checkbox" name="consent" value="yes" required> <?php echo esc_html($t('consent')); ?></label>
      </section>
      <button id="kgpa-send" type="submit"><?php echo esc_html($t('submit')); ?></button>
    </form>
  <?php endif; ?>
<?php endif; ?>
</main><footer class="kgpa-public-footer">Korea Study Portal · <?php echo $lang==='en'?'Official information only':'공식 자료에 근거한 정보 안내'; ?></footer>
</div>
<?php wp_footer(); ?></body></html>

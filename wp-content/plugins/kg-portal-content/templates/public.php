<?php
/** Public P05/P06/P07/P10 — same 480px KG Portal shell as P01, not Kadence get_header(). */
defined('ABSPATH') || exit;
$lang=KGPC_Public::lang();$view=get_query_var('kgpc_view');$slug=(string)get_query_var('kgpc_slug');
$t=static fn($key)=>KGPC_Public::text($key,$lang);
$approved=KGPC_Public::published();
$article=in_array($view,['guide','policy'],true)?KGPC_Public::choose($approved,$view==='guide'?'guide':'policy',$lang,$slug):null;
$empty=!$article&&in_array($view,['guide','policy'],true);
if ($empty)status_header(404);else status_header(200);
$title=match ($view) {'guides','guide'=>$t('guides'),'faq'=>$t('faq'),'policies','policy'=>$t('policies'),default=>'Study in Korea'};
if ($article)$title=$article['title'];
$requested_path=match($view){'guides'=>'guides/','guide'=>'guides/'.$slug.'/','faq'=>'faq/','policies'=>'policy/','policy'=>$slug.'/',default=>''};
$canonical=KGPC_Public::url($lang,$requested_path);
$noindex=$empty || (bool)($article['_fallback']??false) || str_contains((string)wp_parse_url(home_url(),PHP_URL_HOST),'.ddev.site');
?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($noindex): ?><meta name="robots" content="noindex,follow"><?php endif; ?>
<link rel="canonical" href="<?php echo esc_url(($article['_fallback']??false)?KGPC_Public::url('ko',$requested_path):$canonical); ?>">
<?php
$both_locales = !$empty;
if ($article) {
  $kind=$view==='guide'?'guide':'policy';
  $all_languages=array_values(array_filter($approved,static fn($a)=>$a['kind']===$kind&&$a['slug']===$slug));
  $available_languages=array_column($all_languages,'lang');
  $both_locales=in_array('ko',$available_languages,true)&&in_array('en',$available_languages,true);
}
if ($both_locales && !($article['_fallback']??false)):
?>
<link rel="alternate" hreflang="ko" href="<?php echo esc_url(KGPC_Public::url('ko',$requested_path)); ?>">
<link rel="alternate" hreflang="en" href="<?php echo esc_url(KGPC_Public::url('en',$requested_path)); ?>">
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class('kgp-public kgpc-public'); ?>>
<?php wp_body_open(); ?>
<a class="kgp-skip" href="#kgpc-main"><?php echo $lang==='ko'?'본문으로 이동':'Skip to content'; ?></a>
<div class="kgp-shell">
  <header class="kgp-site-header">
    <div class="kgp-header-line">
      <a class="kgp-brand" href="<?php echo esc_url(KGPC_Public::url($lang,'')); ?>">
        <span class="kgp-brand-symbol" aria-hidden="true">K</span>
        <span class="kgp-brand-copy"><strong><?php echo $lang==='ko'?'한국 대학 유학 정보 포털':'Korea University Study Portal'; ?></strong><small><?php echo $lang==='ko'?'공식 대학 정보 안내':'Verified official university information'; ?></small></span>
      </a>
      <nav class="kgp-language" aria-label="Language">
        <a href="<?php echo esc_url(KGPC_Public::url('ko',$requested_path)); ?>" <?php if($lang==='ko')echo 'aria-current="true"'; ?>>한국어</a><span aria-hidden="true">/</span>
        <a href="<?php echo esc_url(KGPC_Public::url('en',$requested_path)); ?>" <?php if($lang==='en')echo 'aria-current="true"'; ?>>EN</a>
      </nav>
    </div>
    <nav class="kgp-nav" aria-label="<?php echo $lang==='ko'?'주 메뉴':'Main navigation'; ?>">
      <?php foreach ([['','홈','Home'],['universities/','대학 찾기','Find universities'],['admissions/','모집 일정','Admissions'],['guides/','한국 유학 가이드','Study in Korea'],['faq/','FAQ','FAQ'],['contact/','문의하기','Contact']] as $nav):
        $active=($view==='guides'||$view==='guide')?$nav[0]==='guides/':$view==='faq'&&$nav[0]==='faq/'; ?>
        <a href="<?php echo esc_url(KGPC_Public::url($lang,$nav[0])); ?>" <?php if ($active)echo 'aria-current="page"'; ?>><?php echo esc_html($nav[$lang==='en'?2:1]); ?></a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main id="kgpc-main" class="kgpc-main" tabindex="-1">
  <?php if ($view==='guides'): ?>
    <div class="kgpc-eyebrow"><?php echo esc_html($t('guides')); ?></div>
    <h1><?php echo esc_html($t('guides')); ?></h1><p class="kgpc-lead"><?php echo esc_html($t('intro')); ?></p>
    <section class="kgpc-topic-grid" aria-label="<?php echo esc_attr($t('guides')); ?>">
    <?php foreach(KGPC_Content::TOPICS as $topic=>$labels):
      $stories=array_values(array_filter($approved,static fn($a)=>$a['kind']==='guide'&&$a['topic']===$topic&&$a['lang']===$lang));
      if (!$stories&&$lang==='en')$stories=array_values(array_filter($approved,static fn($a)=>$a['kind']==='guide'&&$a['topic']===$topic&&$a['lang']==='ko'));
    ?>
      <article class="kgpc-card"><h2><?php echo esc_html($labels[$lang==='en'?1:0]); ?></h2>
        <?php if (!$stories): ?><p class="kgpc-muted"><?php echo esc_html($t('empty')); ?></p>
        <?php else: ?><ul><?php foreach($stories as $story): ?>
          <li><a href="<?php echo esc_url(KGPC_Public::url($lang,'guides/'.$story['slug'].'/')); ?>"><?php echo esc_html($story['title']); ?></a> <span aria-hidden="true">›</span></li>
        <?php endforeach; ?></ul><?php endif; ?>
      </article>
    <?php endforeach; ?>
    </section>
  <?php elseif ($view==='guide'): ?>
    <p><a class="kgpc-back" href="<?php echo esc_url(KGPC_Public::url($lang,'guides/')); ?>">← <?php echo esc_html($t('back')); ?></a></p>
    <?php if (!$article): ?><h1><?php echo esc_html($t('unavailable')); ?></h1><p><?php echo esc_html($t('pending')); ?></p>
    <?php else: ?>
      <?php if(!empty($article['_fallback'])): ?><div class="kgpc-notice" role="status"><?php echo esc_html($t('translation')); ?></div><?php endif; ?>
      <div class="kgpc-eyebrow"><?php echo esc_html(KGPC_Content::TOPICS[$article['topic']][$lang==='en'?1:0]??$t('guides')); ?></div>
      <h1><?php echo esc_html($article['title']); ?></h1>
      <dl class="kgpc-facts"><dt><?php echo esc_html($t('audience')); ?></dt><dd><?php echo esc_html($article['audience']); ?></dd>
        <dt><?php echo esc_html($t('basis')); ?></dt><dd><?php echo esc_html($article['basis_date']?:$t('date_not_given')); ?></dd>
        <dt><?php echo esc_html($t('checked')); ?></dt><dd><?php echo esc_html($article['checked_at']); ?></dd></dl>
      <?php echo KGPC_Public::preview_html($article); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized with wp_kses_post ?>
      <?php echo KGPC_Public::sources($article,$lang); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized HTML builder ?>
      <section class="kgpc-next"><h2><?php echo esc_html($t('related')); ?></h2><p><a href="<?php echo esc_url(KGPC_Public::url($lang,'guides/')); ?>"><?php echo esc_html($t('back')); ?></a> · <a href="<?php echo esc_url(KGPC_Public::url($lang,'universities/')); ?>"><?php echo esc_html($t('university')); ?></a> · <a href="<?php echo esc_url(KGPC_Public::url($lang,'contact/')); ?>"><?php echo esc_html($t('contact')); ?></a></p><p class="kgpc-muted"><?php echo esc_html($t('scope')); ?></p></section>
    <?php endif; ?>
  <?php elseif ($view==='faq'): ?>
    <div class="kgpc-eyebrow">FAQ</div><h1><?php echo esc_html($t('faq')); ?></h1><p class="kgpc-lead"><?php echo esc_html($t('faq_intro')); ?></p>
    <?php $cat=isset($_GET['category'])&&is_scalar($_GET['category'])?sanitize_key((string)$_GET['category']):'';
    if($cat&&!isset(KGPC_Content::FAQ_CATS[$cat]))$cat=''; ?>
    <nav class="kgpc-category-nav" aria-label="FAQ categories"><a <?php if (!$cat)echo 'aria-current="page"'; ?> href="<?php echo esc_url(KGPC_Public::url($lang,'faq/')); ?>"><?php echo $lang==='ko'?'전체':'All'; ?></a>
      <?php foreach(KGPC_Content::FAQ_CATS as $key=>$labels): ?><a <?php if($key===$cat)echo 'aria-current="page"'; ?> href="<?php echo esc_url(add_query_arg('category',$key,KGPC_Public::url($lang,'faq/'))); ?>"><?php echo esc_html($labels[$lang==='en'?1:0]); ?></a><?php endforeach; ?></nav>
    <?php $questions=array_values(array_filter($approved,static fn($x)=>$x['kind']==='faq'&&($cat===''||$x['category']===$cat)));
    $groups=[];
    foreach($questions as $q){$key=$q['slug'];if(!isset($groups[$key]))$groups[$key]=[];$groups[$key][$q['lang']]=$q;}
    $visible=[];
    foreach($groups as $variants){$pick=$variants[$lang]??($lang==='en'?($variants['ko']??null):null);if($pick){if($pick['lang']!==$lang)$pick['_fallback']=true;$visible[]=$pick;}}
    if(!$visible): ?><p class="kgpc-empty"><?php echo esc_html($t('empty')); ?></p><?php endif; ?>
    <?php foreach($visible as $faq): ?>
      <details class="kgpc-faq"><summary><?php echo esc_html($faq['title']); ?> <span class="kgpc-expand" aria-hidden="true">＋</span></summary>
        <?php if(!empty($faq['_fallback'])): ?><p role="status" class="kgpc-notice"><?php echo esc_html($t('translation')); ?></p><?php endif; ?>
        <div class="kgpc-faq-answer"><?php echo KGPC_Public::preview_html($faq); ?>
          <?php if(!empty($faq['audience'])): ?><p><strong><?php echo esc_html($t('audience')); ?>:</strong> <?php echo esc_html($faq['audience']); ?></p><?php endif; ?>
          <?php echo KGPC_Public::sources($faq,$lang); ?></div>
      </details>
    <?php endforeach; ?>
    <div class="kgpc-next"><p><a href="<?php echo esc_url(KGPC_Public::url($lang,'contact/')); ?>"><?php echo esc_html($t('contact')); ?> ↗</a></p></div>
  <?php elseif ($view==='policies'): ?>
    <h1><?php echo esc_html($t('policies')); ?></h1><div class="kgpc-topic-grid">
    <?php foreach(KGPC_Content::POLICIES as $key=>$labels): ?><article class="kgpc-card"><h2><?php echo esc_html($labels[$lang==='en'?1:0]); ?></h2>
      <?php $policy=KGPC_Public::choose($approved,'policy',$lang,$key); ?>
      <?php if($policy): ?><a href="<?php echo esc_url(KGPC_Public::url($lang,$key.'/')); ?>"><?php echo esc_html($t('see')); ?> ↗</a><?php else: ?><p class="kgpc-muted"><?php echo esc_html($t('empty')); ?></p><?php endif; ?></article><?php endforeach; ?>
    </div>
  <?php elseif ($view==='policy'): ?>
    <p><a href="<?php echo esc_url(KGPC_Public::url($lang,'policy/')); ?>">← <?php echo esc_html($t('policies')); ?></a></p>
    <?php if (!$article): ?><h1><?php echo esc_html($t('unavailable')); ?></h1><p><?php echo esc_html($t('pending')); ?></p>
    <?php else: ?>
      <?php if(!empty($article['_fallback'])): ?><p role="status" class="kgpc-notice"><?php echo esc_html($t('translation')); ?></p><?php endif; ?>
      <h1><?php echo esc_html($article['title']); ?></h1><dl class="kgpc-facts">
        <dt><?php echo esc_html($t('effective')); ?></dt><dd><?php echo esc_html($article['effective_at']); ?></dd>
        <?php if($article['revised_at']): ?><dt><?php echo esc_html($t('revised')); ?></dt><dd><?php echo esc_html($article['revised_at']); ?></dd><?php endif; ?>
        <dt><?php echo esc_html($t('operator')); ?></dt><dd><?php echo esc_html($article['contact']); ?></dd></dl>
      <?php echo KGPC_Public::preview_html($article); ?>
    <?php endif; ?>
  <?php endif; ?>
  </main>
  <?php require get_stylesheet_directory() . '/partials/kgp-footer.php'; ?>
</div>
<?php wp_footer(); ?></body></html>

<?php
/** Front-end v0.5: production-shaped pages, source-gated KGP v0.4 API. */
defined('ABSPATH') || exit;
$lang=kgp_fe_lang();
$screen=(string)get_query_var('kgp_screen');
$valid_screens=['home','search','university','admissions','guides','guide','faq','contact'];
if (!in_array($screen,$valid_screens,true)) {status_header(404);$screen='not_found';}
else {status_header(200);}
if ($screen==='university' && !preg_match('/^[A-Za-z0-9_-]{2,64}$/D',(string)get_query_var('kgp_uni'))) {status_header(404);$screen='not_found';}
$menu=[
    ['home','',kgp_fe_t('nav.home','Home')],
    ['search','universities/',kgp_fe_t('nav.search','Find universities')],
    ['admissions','admissions/',kgp_fe_t('nav.admissions','Admissions')],
    ['guides','guides/',kgp_fe_t('nav.guides','Study guides')],
    ['faq','faq/','FAQ'],
    ['contact','contact/',kgp_fe_t('nav.contact','Contact')],
];
?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('kgp-public'); ?>>
<?php wp_body_open(); ?>
<a class="kgp-skip" href="#kgp-main"><?php echo esc_html(kgp_fe_t('nav.skip','Skip to main content')); ?></a>
<div class="kgp-shell">
<header class="kgp-site-header">
    <div class="kgp-header-line">
        <a class="kgp-brand" href="<?php echo esc_url(kgp_fe_url($lang)); ?>">
            <span class="kgp-brand-symbol" aria-hidden="true">K</span>
            <span><strong><?php echo esc_html(kgp_fe_t('brand.title','Korea Study Portal')); ?></strong><small><?php echo esc_html(kgp_fe_t('brand.subtitle','Official university information')); ?></small></span>
        </a>
        <div class="kgp-language" aria-label="<?php echo esc_attr(kgp_fe_t('nav.language','Language')); ?>">
            <a href="<?php echo esc_url(kgp_fe_switch_url('ko')); ?>" <?php echo $lang==='ko'?'aria-current="true"':''; ?>>한국어</a>
            <span aria-hidden="true">/</span>
            <a href="<?php echo esc_url(kgp_fe_switch_url('en')); ?>" <?php echo $lang==='en'?'aria-current="true"':''; ?>>EN</a>
        </div>
    </div>
    <nav class="kgp-nav" aria-label="<?php echo esc_attr(kgp_fe_t('nav.main','Main navigation')); ?>">
        <?php foreach ($menu as [$id,$route,$title]): ?>
            <a href="<?php echo esc_url(kgp_fe_url($lang,$route)); ?>" <?php echo ($screen===$id||($id==='guides'&&$screen==='guide')||($id==='search'&&$screen==='university'))?'aria-current="page"':''; ?>><?php echo esc_html($title); ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main id="kgp-main" tabindex="-1">
<?php
if ($screen==='home') kgp_fe_screen_home($lang);
elseif ($screen==='search') kgp_fe_screen_search($lang);
elseif ($screen==='university') kgp_fe_screen_university($lang,(string)get_query_var('kgp_uni'));
elseif ($screen==='admissions') kgp_fe_screen_admissions($lang);
elseif ($screen==='guides' || $screen==='guide') kgp_fe_screen_guides($lang,$screen);
elseif ($screen==='faq') kgp_fe_screen_pending('FAQ');
elseif ($screen==='contact') kgp_fe_screen_pending(kgp_fe_t('nav.contact','Contact'));
else kgp_fe_notice(kgp_fe_t('state.not_found','Page not found.'),'error');
?>
</main>
<?php require get_stylesheet_directory() . '/partials/kgp-footer.php'; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
function kgp_fe_screen_home(string $lang): void {
    $result=kgp_fe_api('catalog',['locale'=>$lang,'admission_status'=>'open']);
    ?>
    <section class="kgp-hero">
        <p class="kgp-eyebrow"><?php echo esc_html(kgp_fe_t('home.eyebrow','Study in Korea / Official sources only')); ?></p>
        <h1><?php echo esc_html(kgp_fe_t('home.title','Find a Korean university with confidence.')); ?></h1>
        <p class="kgp-lead"><?php echo esc_html(kgp_fe_t('home.intro','Explore verified admissions, degree programs, tuition and application periods.')); ?></p>
        <form class="kgp-search-inline" method="get" action="<?php echo esc_url(kgp_fe_url($lang,'universities/')); ?>" role="search">
            <label for="kgp-home-q"><?php echo esc_html(kgp_fe_t('search.name','University or program')); ?></label>
            <div class="kgp-search-row"><input id="kgp-home-q" type="search" name="q" maxlength="120" placeholder="<?php echo esc_attr(kgp_fe_t('home.search_placeholder','Name of a university or major')); ?>"><button type="submit" class="kgp-button"><?php echo esc_html(kgp_fe_t('action.search','Search')); ?></button></div>
        </form>
        <p class="kgp-micro"><?php echo esc_html(kgp_fe_t('home.no_account','No account is required to browse. Applications are completed on official external websites.')); ?></p>
    </section>
    <section class="kgp-section" aria-labelledby="kgp-featured-title">
        <div class="kgp-section-head"><h2 id="kgp-featured-title"><?php echo esc_html(kgp_fe_t('home.open','Currently open admissions')); ?></h2><a class="kgp-textlink" href="<?php echo esc_url(kgp_fe_url($lang,'universities/?admission_status=open')); ?>"><?php echo esc_html(kgp_fe_t('action.all','View all')); ?></a></div>
        <?php if (!$result['ok']):kgp_fe_notice(kgp_fe_t('state.api_error','Information is temporarily unavailable. Please retry.'),'error');
        elseif (empty($result['data']['items'])):kgp_fe_notice(kgp_fe_t('state.no_open','No verified open admissions are currently listed. You can search all universities.'));
        else:
            foreach (array_slice($result['data']['items'],0,4) as $item) kgp_fe_card($item,$lang);
        endif; ?>
    </section>
    <section class="kgp-section kgp-link-tiles" aria-labelledby="kgp-prepare-title">
        <h2 id="kgp-prepare-title"><?php echo esc_html(kgp_fe_t('home.prepare','Prepare for studying in Korea')); ?></h2>
        <a class="kgp-tile" href="<?php echo esc_url(kgp_fe_url($lang,'admissions/')); ?>"><strong><?php echo esc_html(kgp_fe_t('nav.admissions','Admissions')); ?></strong><span aria-hidden="true">&rarr;</span></a>
        <a class="kgp-tile" href="<?php echo esc_url(kgp_fe_url($lang,'guides/')); ?>"><strong><?php echo esc_html(kgp_fe_t('nav.guides','Study guides')); ?></strong><span aria-hidden="true">&rarr;</span></a>
    </section>
    <?php
}

/** Search form with inline native details and multi-select OR within each field. */
function kgp_fe_filter_form(string $lang, string $route, array $values, bool $admissions = false): void {
    $choiceSets=[
        'region'=>[
            'seoul'=>'Seoul','busan'=>'Busan','daegu'=>'Daegu','incheon'=>'Incheon',
            'gwangju'=>'Gwangju','daejeon'=>'Daejeon','ulsan'=>'Ulsan','sejong'=>'Sejong',
            'gyeonggi'=>'Gyeonggi','gangwon'=>'Gangwon','chungbuk'=>'Chungbuk',
            'chungnam'=>'Chungnam','jeonbuk'=>'Jeonbuk','jeonnam'=>'Jeonnam',
            'gyeongbuk'=>'Gyeongbuk','gyeongnam'=>'Gyeongnam','jeju'=>'Jeju',
        ],
        'field'=>[
            'engineering'=>'Engineering','science'=>'Natural sciences','humanities'=>'Humanities',
            'social_science'=>'Social sciences','business'=>'Business','medicine'=>'Medicine',
            'arts'=>'Arts and design','education'=>'Education','other'=>'Other',
        ],
        'level'=>['bachelor'=>'Bachelor','master'=>'Master','doctoral'=>'Doctoral','language'=>'Language course'],
        'teaching_lang'=>['ko'=>'Korean','en'=>'English'],
        'admission_status'=>['open'=>'Open','upcoming'=>'Upcoming','closed'=>'Closed','cancelled'=>'Cancelled','unknown'=>'Unknown'],
    ];
    $labels=['region'=>['filter.region','Region'],'field'=>['filter.field','Field of study'],
        'level'=>['filter.level','Degree / course'],'teaching_lang'=>['filter.teaching_lang','Teaching language'],
        'admission_status'=>['filter.admission_status','Admission status']];
    ?>
    <form class="kgp-filter-form" id="kgp-filter-form" method="get" action="<?php echo esc_url(kgp_fe_url($lang,$route)); ?>">
      <?php if (!$admissions): ?>
        <label for="kgp-query"><?php echo esc_html(kgp_fe_t('search.name','University or program')); ?></label>
        <div class="kgp-search-row"><input id="kgp-query" type="search" name="q" maxlength="120" value="<?php echo kgp_fe_attr($values['q']??''); ?>" placeholder="<?php echo esc_attr(kgp_fe_t('search.placeholder','Search verified names')); ?>"><button class="kgp-button" type="submit"><?php echo esc_html(kgp_fe_t('action.search','Search')); ?></button></div>
      <?php endif; ?>
      <details class="kgp-filter-panel" <?php echo count($values)>0?'open':''; ?>>
        <summary><?php echo esc_html(kgp_fe_t('filter.title','Filter by conditions')); ?></summary>
        <div class="kgp-filter-content">
          <?php foreach ($choiceSets as $key=>$options):
            if ($admissions && $key==='teaching_lang') continue;
            $selected=isset($values[$key])?explode(',',(string)$values[$key]):[];
            foreach ($selected as $unknown) {if ($unknown!=='' && !isset($options[$unknown]) && preg_match('/^[A-Za-z0-9_-]{1,48}$/D',$unknown)) $options[$unknown]=$unknown;} ?>
            <fieldset class="kgp-filter-group"><legend><?php echo esc_html(kgp_fe_t($labels[$key][0],$labels[$key][1])); ?></legend>
              <div class="kgp-chip-grid">
              <?php foreach ($options as $code=>$name): ?>
                <label class="kgp-check"><input name="<?php echo esc_attr($key); ?>[]" type="checkbox" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code,$selected,true)); ?>><span><?php echo esc_html(kgp_fe_t('choice.'.$key.'.'.$code,$name)); ?></span></label>
              <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endforeach; ?>
          <?php if (!$admissions): ?>
            <div class="kgp-filter-stack"><label for="kgp-cert"><?php echo esc_html(kgp_fe_t('filter.certified','Accredited university')); ?></label>
              <select name="certified" id="kgp-cert"><option value=""><?php echo esc_html(kgp_fe_t('filter.any','Any')); ?></option><option value="true" <?php selected($values['certified']??'','true'); ?>><?php echo esc_html(kgp_fe_t('filter.verified_yes','Verified yes')); ?></option><option value="false" <?php selected($values['certified']??'','false'); ?>><?php echo esc_html(kgp_fe_t('filter.verified_no','Verified no')); ?></option></select></div>
            <div class="kgp-filter-stack"><label for="kgp-eng-only"><?php echo esc_html(kgp_fe_t('filter.english_only','100% English-taught degree')); ?></label>
              <select name="english_only" id="kgp-eng-only"><option value=""><?php echo esc_html(kgp_fe_t('filter.any','Any')); ?></option><option value="true" <?php selected($values['english_only']??'','true'); ?>><?php echo esc_html(kgp_fe_t('filter.verified_yes','Verified yes')); ?></option><option value="false" <?php selected($values['english_only']??'','false'); ?>><?php echo esc_html(kgp_fe_t('filter.verified_no','Verified no')); ?></option></select></div>
            <div class="kgp-filter-stack"><label for="kgp-unit"><?php echo esc_html(kgp_fe_t('filter.cost_unit','Tuition charge unit')); ?></label>
              <select name="cost_unit" id="kgp-unit"><option value=""><?php echo esc_html(kgp_fe_t('filter.any','Any')); ?></option>
                <?php foreach (['semester','month','year','one_time','program_total','term','other'] as $unit): ?><option value="<?php echo esc_attr($unit); ?>" <?php selected($values['cost_unit']??'',$unit); ?>><?php echo esc_html(kgp_fe_units($unit)); ?></option><?php endforeach; ?>
              </select></div>
            <div class="kgp-range"><div><label for="kgp-min"><?php echo esc_html(kgp_fe_t('filter.min','Minimum tuition (KRW)')); ?></label><input id="kgp-min" name="tuition_min" type="number" min="0" step="0.01" inputmode="decimal" value="<?php echo kgp_fe_attr($values['tuition_min']??''); ?>"></div><div><label for="kgp-max"><?php echo esc_html(kgp_fe_t('filter.max','Maximum tuition (KRW)')); ?></label><input id="kgp-max" name="tuition_max" type="number" min="0" step="0.01" inputmode="decimal" value="<?php echo kgp_fe_attr($values['tuition_max']??''); ?>"></div></div>
          <?php endif; ?>
          <?php if (!$admissions): ?>
            <div class="kgp-filter-stack"><label for="kgp-sort"><?php echo esc_html(kgp_fe_t('filter.sort','Sort by')); ?></label>
            <select name="sort" id="kgp-sort">
              <option value="name" <?php selected($values['sort']??'name','name'); ?>><?php echo esc_html(kgp_fe_t('sort.name','University name')); ?></option>
              <option value="tuition" <?php selected($values['sort']??'','tuition'); ?>><?php echo esc_html(kgp_fe_t('sort.tuition','Tuition, lowest first')); ?></option>
              <option value="deadline" <?php selected($values['sort']??'','deadline'); ?>><?php echo esc_html(kgp_fe_t('sort.deadline','Earliest deadline')); ?></option>
            </select><p class="kgp-micro"><?php echo esc_html(kgp_fe_t('sort.help','Tuition sort requires exactly one degree level and one original tuition unit.')); ?></p></div>
          <?php endif; ?>
          <p class="kgp-micro"><?php echo esc_html(kgp_fe_t('filter.logic','Multiple choices within one filter are OR. Different filters are AND. Tuition units are never converted.')); ?></p>
          <p role="alert" id="kgp-filter-client-error" class="kgp-form-error" tabindex="-1" hidden></p>
          <div class="kgp-filter-actions"><button type="submit" class="kgp-button"><?php echo esc_html(kgp_fe_t('action.apply','Apply')); ?></button><a class="kgp-button kgp-button--secondary" href="<?php echo esc_url(kgp_fe_url($lang,$route)); ?>"><?php echo esc_html(kgp_fe_t('action.reset','Reset all')); ?></a></div>
        </div>
      </details>
    </form>
    <?php
}
function kgp_fe_screen_search(string $lang): void {
    $query=kgp_fe_filter_params($_GET);
    $result=kgp_fe_api('catalog',array_merge($query,['locale'=>$lang]));
    ?>
    <section class="kgp-section kgp-page-head"><p class="kgp-eyebrow"><?php echo esc_html(kgp_fe_t('search.eyebrow','Verified courses and admission rounds')); ?></p><h1><?php echo esc_html(kgp_fe_t('nav.search','Find universities')); ?></h1><p><?php echo esc_html(kgp_fe_t('search.intro','Results represent matching programs and admission rounds, not just university counts.')); ?></p></section>
    <?php kgp_fe_filter_form($lang,'universities/',$query); ?>
    <section class="kgp-section" aria-labelledby="kgp-results-heading">
      <div class="kgp-results-title"><h2 id="kgp-results-heading"><?php echo esc_html(kgp_fe_t('search.results','Search results')); ?></h2>
      <?php if ($result['ok']): ?><span><?php echo esc_html(number_format((int)($result['data']['total']??0))); ?> <?php echo esc_html(kgp_fe_t('search.matches','matching offerings')); ?></span><?php endif; ?></div>
      <?php if (!$result['ok']): kgp_fe_notice($result['status']===422?(string)$result['message']:kgp_fe_t('state.api_error','Information is temporarily unavailable. Please retry.'),'error');
      else: ?>
        <?php if (empty($result['data']['items'])):kgp_fe_notice(kgp_fe_t('state.no_results','No approved results match these conditions. Change the filters or reset them.')); endif; ?>
        <?php foreach (($result['data']['items']??[]) as $item) kgp_fe_card($item,$lang); ?>
        <?php kgp_fe_pager($result['data'],'universities/',$query); ?>
      <?php endif; ?>
    </section>
    <?php
}
function kgp_fe_screen_university(string $lang,string $public_id): void {
    $response=kgp_fe_api('universities/'.rawurlencode($public_id),['locale'=>$lang]);
    if (!$response['ok']) {
        if ($response['status']===404) status_header(404);
        kgp_fe_notice($response['status']===404?kgp_fe_t('state.unpublished','This university is not published or its official source is no longer valid.'):kgp_fe_t('state.api_error','Information is temporarily unavailable. Please retry.'),'error');
        return;
    }
    $u=$response['data'];$offerings=is_array($u['offerings']??null)?$u['offerings']:[];
    $selected='';
    if (isset($_GET['offering']) && is_scalar($_GET['offering'])) $selected=sanitize_text_field(wp_unslash((string)$_GET['offering']));
    ?>
    <nav class="kgp-breadcrumb" aria-label="<?php echo esc_attr(kgp_fe_t('nav.breadcrumb','Breadcrumb')); ?>"><a href="<?php echo esc_url(kgp_fe_url($lang,'universities/')); ?>"><?php echo esc_html(kgp_fe_t('nav.search','Find universities')); ?></a> <span aria-hidden="true">/</span> <?php echo kgp_fe_h($u['name']??''); ?></nav>
    <section class="kgp-section kgp-page-head">
      <p class="kgp-eyebrow"><?php echo esc_html(kgp_fe_t('detail.eyebrow','Published university record')); ?></p>
      <h1><?php echo kgp_fe_h($u['name']??''); ?></h1>
      <?php if (!empty($u['official_website'])): ?><p><a class="kgp-textlink" href="<?php echo esc_url(kgp_fe_safe_url($u['official_website'])); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(kgp_fe_t('action.official_site','Official university website')); ?> <span aria-hidden="true">&nearr;</span></a></p><?php endif; ?>
      <?php if (isset($u['official_source']) && is_array($u['official_source'])) kgp_fe_sources([$u['official_source']]); ?>
    </section>
    <section class="kgp-section" aria-labelledby="kgp-offering-heading"><h2 id="kgp-offering-heading"><?php echo esc_html(kgp_fe_t('detail.choose','Select a campus, program and admission round')); ?></h2>
      <?php if (!$offerings):kgp_fe_notice(kgp_fe_t('state.no_offerings','No verified offerings are published for this university.')); ?>
      <?php else: ?>
        <ul class="kgp-offering-list">
        <?php foreach ($offerings as $offer):
            $id=(string)($offer['offering_id']??''); $active=$selected!==''&&$selected===$id; ?>
            <li><a <?php echo $active?'aria-current="true"':''; ?> href="<?php echo esc_url(kgp_fe_detail_url($offer,$lang)); ?>"><strong><?php echo kgp_fe_h($offer['program_name']??''); ?></strong><span><?php echo kgp_fe_h($offer['campus_name']??''); ?> · <?php echo kgp_fe_h($offer['academic_year']??''); ?> <?php echo kgp_fe_h($offer['academic_term']??''); ?> · <?php echo kgp_fe_h(kgp_fe_status((string)($offer['admission']['status']??'unknown'))); ?></span></a></li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php
    if ($selected==='') return;
    if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$selected) || !in_array($selected,array_column($offerings,'offering_id'),true)) {
        kgp_fe_notice(kgp_fe_t('state.invalid_offering','This program or intake is unavailable for this university. Select a valid offering above.'),'error');return;
    }
    $detail=kgp_fe_api('offerings/'.rawurlencode($selected),['locale'=>$lang]);
    if (!$detail['ok'] || ($detail['data']['university_id']??'')!==$public_id) {
        kgp_fe_notice(kgp_fe_t('state.invalid_offering','This program or intake is unavailable for this university. Select a valid offering above.'),'error');return;
    }
    $d=$detail['data'];$admission=$d['admission']??[];
    ?>
    <section class="kgp-section" aria-labelledby="kgp-course-heading">
      <p class="kgp-eyebrow"><?php echo esc_html(kgp_fe_t('detail.selected','Selected offering')); ?></p>
      <h2 id="kgp-course-heading"><?php echo kgp_fe_h($d['program_name']??''); ?></h2>
      <p><?php echo kgp_fe_h($d['campus_name']??''); ?> · <?php echo kgp_fe_h($d['academic_year']??''); ?> <?php echo kgp_fe_h($d['academic_term']??''); ?></p>
      <dl class="kgp-pairs kgp-pairs--large">
        <div><dt><?php echo esc_html(kgp_fe_t('label.status','Admission status')); ?></dt><dd><?php echo kgp_fe_h(kgp_fe_status((string)($admission['status']??'unknown'))); ?></dd></div>
        <div><dt><?php echo esc_html(kgp_fe_t('label.tuition','Published tuition')); ?></dt><dd><?php
          $prices=is_array($d['tuition']??null)?$d['tuition']:[];
          if ($prices) foreach ($prices as $price) echo '<div>'.esc_html(kgp_fe_price($price)).'</div>';
          else echo esc_html(kgp_fe_t('state.no_tuition','Not publicly available'));
        ?></dd></div>
        <?php if (!empty($admission['start_date'])): ?><div><dt><?php echo esc_html(kgp_fe_t('label.opening','Opens')); ?></dt><dd><?php echo kgp_fe_h($admission['start_date']); ?></dd></div><?php endif; ?>
        <?php if (!empty($admission['end_date'])): ?><div><dt><?php echo esc_html(kgp_fe_t('label.deadline','Deadline')); ?></dt><dd><?php echo kgp_fe_h($admission['end_date']); ?><?php if (($admission['end_precision']??'')!=='minute'&&($admission['end_precision']??'')!=='second'): ?><span class="kgp-small"> <?php echo esc_html(kgp_fe_t('label.check_time','Check official time')); ?></span><?php endif; ?></dd></div><?php endif; ?>
      </dl>
      <div class="kgp-action-stack">
      <?php if (!empty($d['intake']['application_url'])):
        $url=kgp_fe_safe_url($d['intake']['application_url']);
        if ($url): ?><a class="kgp-button" href="<?php echo esc_url($url); ?>" rel="noopener noreferrer" target="_blank"><?php echo esc_html(kgp_fe_t('action.apply_official','Go to official application site')); ?> <span aria-hidden="true">&nearr;</span></a><?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($d['university']['official_website'])):
        $url=kgp_fe_safe_url($d['university']['official_website']);
        if ($url): ?><a class="kgp-button kgp-button--secondary" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(kgp_fe_t('action.official_site','Official university website')); ?></a><?php endif; ?>
      <?php endif; ?>
      </div>
      <p class="kgp-micro"><?php echo esc_html(kgp_fe_t('detail.external','The portal does not receive applications. Always verify the latest official requirements.')); ?></p>
    </section>
    <section class="kgp-section" aria-labelledby="kgp-sections-heading">
      <h2 id="kgp-sections-heading"><?php echo esc_html(kgp_fe_t('detail.sections','Published details and evidence')); ?></h2>
      <?php if (empty($d['sections'])):kgp_fe_notice(kgp_fe_t('state.no_sections','No section-level evidence has been published yet.')); ?>
      <?php else:
          foreach ($d['sections'] as $section): ?>
            <details class="kgp-detail-section"><summary><?php echo kgp_fe_h($section['title']??$section['id']); ?></summary><div class="kgp-detail-body">
              <?php foreach (($section['groups']??[]) as $group): ?>
                <div class="kgp-scope"><p class="kgp-scope-title"><?php echo esc_html(kgp_fe_t('scope.'.($group['scope']??''),ucfirst((string)($group['scope']??'')))); ?></p>
                  <?php foreach (($group['fields']??[]) as $field): ?>
                    <section class="kgp-evidence-field">
                      <h3><?php echo esc_html(kgp_fe_field_label((string)($field['field']??''))); ?></h3>
                      <?php kgp_fe_value($field['value']??null); ?>
                      <?php if (!empty($field['translation_pending'])): ?><p class="kgp-translation-note"><?php echo esc_html(kgp_fe_t('detail.translation_pending','Translation under review; latest verified source-language text shown.')); ?></p><?php endif; ?>
                      <?php if (!empty($field['source_documents'])) kgp_fe_sources($field['source_documents']); ?>
                    </section>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div></details>
          <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php
}
function kgp_fe_screen_admissions(string $lang): void {
    $params=kgp_fe_filter_params($_GET);
    $result=kgp_fe_api('admissions',array_merge($params,['locale'=>$lang]));
    ?>
    <section class="kgp-section kgp-page-head"><p class="kgp-eyebrow"><?php echo esc_html(kgp_fe_t('admissions.eyebrow','Official university application dates')); ?></p><h1><?php echo esc_html(kgp_fe_t('nav.admissions','Admissions')); ?></h1><p><?php echo esc_html(kgp_fe_t('admissions.intro','Dates and times follow each official publication. An unpublished deadline time is not assumed.')); ?></p></section>
    <?php kgp_fe_filter_form($lang,'admissions/',$params,true); ?>
    <section class="kgp-section" aria-labelledby="kgp-admissions-heading">
      <div class="kgp-results-title"><h2 id="kgp-admissions-heading"><?php echo esc_html(kgp_fe_t('admissions.list','Admission rounds')); ?></h2><?php if ($result['ok']): ?><span><?php echo esc_html((string)($result['data']['total']??0)); ?></span><?php endif; ?></div>
      <?php if (!$result['ok']):kgp_fe_notice(kgp_fe_t('state.api_error','Information is temporarily unavailable. Please retry.'),'error');
      elseif (empty($result['data']['items'])):kgp_fe_notice(kgp_fe_t('state.no_results','No approved results match these conditions. Change the filters or reset them.'));
      else:
         foreach ($result['data']['items'] as $item) kgp_fe_card($item,$lang);
         kgp_fe_pager($result['data'],'admissions/',$params);
      endif; ?>
    </section>
    <?php
}
function kgp_fe_screen_guides(string $lang,string $screen): void {
    $topics = [
        'visa'=>['guide.visa','D-2 / D-4 student visas'],
        'part-time-work'=>['guide.work','Part-time work permits'],
        'settling-in'=>['guide.settle','Settling in Korea'],
        'post-graduation'=>['guide.after','Post-graduation visas'],
        'preparation'=>['guide.timeline','Application preparation timeline'],
        'emergency'=>['guide.emergency','Emergency contacts and fraud prevention'],
    ];
    $slug=(string)get_query_var('kgp_guide');
    if ($screen==='guide' && !isset($topics[$slug])) {status_header(404);kgp_fe_notice(kgp_fe_t('state.not_found','Page not found.'),'error');return;}
    ?>
    <section class="kgp-section kgp-page-head"><p class="kgp-eyebrow"><?php echo esc_html(kgp_fe_t('guide.eyebrow','Study in Korea, step by step')); ?></p><h1><?php echo esc_html($screen==='guide'?kgp_fe_t($topics[$slug][0],$topics[$slug][1]):kgp_fe_t('nav.guides','Study guides')); ?></h1><p><?php echo esc_html(kgp_fe_t('guide.intro','Official guide content will be published here only after source and applicability review.')); ?></p></section>
    <?php if ($screen==='guides'): ?>
      <div class="kgp-link-tiles"><?php foreach ($topics as $id=>$title): ?><a class="kgp-tile" href="<?php echo esc_url(kgp_fe_url($lang,'guides/'.$id.'/')); ?>"><strong><?php echo esc_html(kgp_fe_t($title[0],$title[1])); ?></strong><span aria-hidden="true">&rarr;</span></a><?php endforeach; ?></div>
    <?php else: kgp_fe_notice(kgp_fe_t('guide.pending','This guide is awaiting verified official source content. No unverified guidance is displayed.')); endif;
}
function kgp_fe_screen_pending(string $title): void {
    echo '<section class="kgp-section kgp-page-head"><h1>'.esc_html($title).'</h1></section>';
    kgp_fe_notice(kgp_fe_t('state.pending','This feature is still under implementation. It will not accept or publish information until its server-side workflow is complete.'));
}

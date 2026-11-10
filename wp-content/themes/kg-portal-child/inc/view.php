<?php
defined('ABSPATH') || exit;

function kgp_fe_t(string $key, string $english, ?string $lang = null): string {
    if (($lang ?? kgp_fe_lang()) !== 'ko') return $english;
    static $dict = null;
    if ($dict === null) {
        $path = KGP_FE_DIR . '/i18n/ko.json';
        $dict = is_readable($path) ? json_decode((string)file_get_contents($path), true) : [];
        if (!is_array($dict)) $dict = [];
    }
    return isset($dict[$key]) && is_string($dict[$key]) ? $dict[$key] : $english;
}

function kgp_fe_filter_params(array $raw, bool $keep_page = true): array {
    $out=[];
    foreach (['q','region','field','level','teaching_lang','admission_status','english_only',
        'certified','cost_unit','tuition_min','tuition_max','sort','page'] as $key) {
        if ($key === 'page' && !$keep_page) continue;
        if (!isset($raw[$key])) continue;
        $value = wp_unslash($raw[$key]);
        if (is_array($value)) {
            $value = array_slice(array_values(array_filter($value, 'is_scalar')), 0, 20);
            $value = implode(',', array_unique(array_map('sanitize_text_field', $value)));
        } elseif (is_scalar($value)) {
            $value = sanitize_text_field((string)$value);
        } else continue;
        if ((string)$value !== '') $out[$key] = $value;
    }
    return $out;
}

/** All requests pass through the exact public REST contract (no direct table reads). */
function kgp_fe_api(string $path, array $params = []): array {
    $req = new WP_REST_Request('GET', '/kgp/v1/' . ltrim($path, '/'));
    $req->set_query_params($params);
    $res = rest_do_request($req);
    if (is_wp_error($res)) return ['ok'=>false, 'status'=>500, 'message'=>__('Data service is currently unavailable.', 'kg-portal-child')];
    $status = (int)$res->get_status();
    if ($status < 200 || $status >= 300) {
        $data = $res->get_data();
        $message = is_array($data) ? (string)($data['message'] ?? '') : '';
        return ['ok'=>false,'status'=>$status,'message'=>$status===404 ? 'not_found' : ($status < 500 && $message!=='' ? $message : 'service_unavailable')];
    }
    $data=$res->get_data();
    return ['ok'=>true,'status'=>$status,'data'=>is_array($data)?$data:[]];
}

function kgp_fe_h($value): string { return esc_html(is_scalar($value) ? (string)$value : ''); }
function kgp_fe_attr($value): string { return esc_attr(is_scalar($value) ? (string)$value : ''); }
function kgp_fe_safe_url($value): string {
    if (!is_string($value)) return '';
    $url = esc_url($value, ['http','https']);
    return $url && preg_match('~^https?://~i', $url) ? $url : '';
}
function kgp_fe_status(string $status): string {
    $labels = [
        'open'=>['status.open','Open'], 'upcoming'=>['status.upcoming','Upcoming'],
        'closed'=>['status.closed','Closed'], 'cancelled'=>['status.cancelled','Cancelled'],
        'unknown'=>['status.unknown','Check official schedule'],
    ];
    $pair = $labels[$status] ?? ['status.unknown','Check official schedule'];
    return kgp_fe_t($pair[0], $pair[1]);
}
function kgp_fe_units(string $unit): string {
    $pairs = [
        'semester'=>['unit.semester','semester'],'month'=>['unit.month','month'],
        'year'=>['unit.year','year'],'one_time'=>['unit.one_time','one-time'],
        'program_total'=>['unit.program_total','full program'],'term'=>['unit.term','term'],
        'other'=>['unit.other','official unit'],
    ];
    $pair = $pairs[$unit] ?? null;
    return $pair ? kgp_fe_t($pair[0],$pair[1]) : $unit;
}
function kgp_fe_price(array $p): string {
    if (($p['currency']??'')!=='KRW' || !isset($p['min'],$p['max'],$p['unit'])) return '';
    $min = (float)$p['min']; $max = (float)$p['max'];
    if (!is_finite($min) || !is_finite($max) || $min < 0 || $max < $min) return '';
    $label = number_format($min, $min===floor($min)?0:2);
    if ($max !== $min) $label .= ' - ' . number_format($max, $max===floor($max)?0:2);
    return $label . ' KRW / ' . kgp_fe_units((string)$p['unit']);
}
function kgp_fe_detail_url(array $record, string $lang): string {
    $uni=(string)($record['university_id']??''); $offering=(string)($record['offering_id']??'');
    if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$uni) || !preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$offering)) return kgp_fe_url($lang,'universities/');
    return add_query_arg(['offering'=>$offering],kgp_fe_url($lang,'universities/'.rawurlencode($uni).'/'));
}
function kgp_fe_card(array $item, string $lang): void {
    $status=(string)($item['admission']['status']??'unknown');
    $p = is_array($item['tuition']??null) && $item['tuition'] ? kgp_fe_price($item['tuition'][0]) : '';
    $region=(string)($item['campus_region']??'');
    $url = kgp_fe_detail_url($item,$lang);
    ?>
    <article class="kgp-card" data-offering-id="<?php echo kgp_fe_attr($item['offering_id']??''); ?>">
        <div class="kgp-card__top">
            <span class="kgp-badge kgp-badge--<?php echo kgp_fe_attr($status); ?>"><?php echo kgp_fe_h(kgp_fe_status($status)); ?></span>
            <?php if ($region): ?><span class="kgp-meta"><?php echo kgp_fe_h(strtoupper($region)); ?></span><?php endif; ?>
        </div>
        <h3 class="kgp-card__title"><a href="<?php echo esc_url($url); ?>"><?php echo kgp_fe_h($item['university_name']??''); ?></a></h3>
        <p class="kgp-card__program"><?php echo kgp_fe_h($item['program_name']??''); ?><?php if (!empty($item['campus_name'])): ?> · <?php echo kgp_fe_h($item['campus_name']); ?><?php endif; ?></p>
        <dl class="kgp-pairs">
            <div><dt><?php echo kgp_fe_h(kgp_fe_t('label.level','Degree')); ?></dt><dd><?php echo kgp_fe_h((string)($item['level']??'')); ?></dd></div>
            <div><dt><?php echo kgp_fe_h(kgp_fe_t('label.tuition','Published tuition')); ?></dt><dd><?php echo $p?kgp_fe_h($p):kgp_fe_h(kgp_fe_t('state.no_tuition','Not publicly available')); ?></dd></div>
            <?php if (!empty($item['admission']['end_date'])): ?><div><dt><?php echo kgp_fe_h(kgp_fe_t('label.deadline','Deadline')); ?></dt><dd><?php echo kgp_fe_h($item['admission']['end_date']); ?> <span class="kgp-small"><?php echo kgp_fe_h(kgp_fe_t('label.check_time','Check official time')); ?></span></dd></div><?php endif; ?>
            <?php if (($item['certified']??null)!==null): ?><div><dt><?php echo kgp_fe_h(kgp_fe_t('label.certified','Accreditation')); ?></dt><dd><?php echo kgp_fe_h(($item['certified']??false)?kgp_fe_t('label.yes','Verified yes'):kgp_fe_t('label.no','Verified no')); ?></dd></div><?php endif; ?>
        </dl>
        <a class="kgp-textlink" href="<?php echo esc_url($url); ?>"><?php echo kgp_fe_h(kgp_fe_t('action.details','View official details')); ?> <span aria-hidden="true">&rarr;</span></a>
    </article>
    <?php
}
function kgp_fe_notice(string $message, string $type = 'info'): void {
    echo '<p role="status" class="kgp-notice kgp-notice--'.esc_attr($type).'">'.esc_html($message).'</p>';
}
function kgp_fe_pager(array $result,string $path,array $params): void {
    $page=(int)($result['page']??1);$totalPages=(int)($result['total_pages']??0);
    if ($totalPages<=1) return;
    $base=kgp_fe_url(kgp_fe_lang(),$path);
    echo '<nav class="kgp-pager" aria-label="'.esc_attr(kgp_fe_t('label.pagination','Result pages')).'">';
    if ($page>1) echo '<a class="kgp-button kgp-button--secondary" href="'.esc_url(add_query_arg(array_merge($params,['page'=>$page-1]),$base)).'">'.esc_html(kgp_fe_t('action.previous','Previous')).'</a>';
    echo '<span aria-current="page">'.esc_html(sprintf('%d / %d',$page,$totalPages)).'</span>';
    if ($page<$totalPages) echo '<a class="kgp-button kgp-button--secondary" href="'.esc_url(add_query_arg(array_merge($params,['page'=>$page+1]),$base)).'">'.esc_html(kgp_fe_t('action.next','Next')).'</a>';
    echo '</nav>';
}

/** Formats only approved public values, including structured and repeated items. */
function kgp_fe_value($value, int $depth = 0): void {
    if ($value === null || $depth > 6) return;
    if (is_bool($value)) { echo '<span>'.esc_html($value?kgp_fe_t('value.yes','Yes'):kgp_fe_t('value.no','No')).'</span>';return; }
    if (is_string($value) || is_numeric($value)) {
        $str=(string)$value;
        $url=kgp_fe_safe_url($str);
        if ($url !== '') echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($str).'</a>';
        else echo '<span class="kgp-prewrap">'.esc_html($str).'</span>';
        return;
    }
    if (!is_array($value)) return;
    if (isset($value['currency'],$value['unit']) && ($value['currency']==='KRW')) {
        $min=$value['amount']??$value['min_amount']??null;$max=$value['amount']??$value['max_amount']??null;
        if (is_numeric($min) && is_numeric($max)) {
            echo '<strong>'.esc_html(kgp_fe_price(['currency'=>'KRW','unit'=>$value['unit'],'min'=>(float)$min,'max'=>(float)$max])).'</strong>';
        }
    }
    if (array_is_list($value)) {
        echo '<ul class="kgp-value-list">';
        foreach ($value as $item) {echo '<li>';kgp_fe_value($item,$depth+1);echo '</li>';}
        echo '</ul>';return;
    }
    $ignored=['currency','amount','min_amount','max_amount','unit'];
    $rows=array_diff(array_keys($value),$ignored);
    if (!$rows) return;
    echo '<dl class="kgp-structured">';
    foreach ($rows as $key) {
        echo '<div><dt>'.esc_html(kgp_fe_field_label((string)$key)).'</dt><dd>';
        kgp_fe_value($value[$key],$depth+1);
        echo '</dd></div>';
    }
    echo '</dl>';
}
function kgp_fe_field_label(string $field): string {
    $label=ucfirst(str_replace('_',' ',$field));
    return kgp_fe_t('field.'.$field,$label);
}
function kgp_fe_sources(array $sources): void {
    if (!$sources) return;
    echo '<details class="kgp-source"><summary>'.esc_html(kgp_fe_t('label.sources','Official source and verified date')).'</summary><ul>';
    foreach ($sources as $source) {
        $url=kgp_fe_safe_url($source['public_url']??'');
        echo '<li>';
        if ($url) echo '<a href="'.esc_url($url).'" rel="noopener noreferrer" target="_blank">'.esc_html((string)($source['title']??$url)).'</a>';
        else echo esc_html((string)($source['title']??''));
        foreach (['document_locator','locator','applicability','checked_at','valid_from','valid_to'] as $key) {
            if (!empty($source[$key])) echo '<span class="kgp-source-meta">'.esc_html(kgp_fe_field_label($key)).': '.esc_html((string)$source[$key]).'</span>';
        }
        echo '</li>';
    }
    echo '</ul></details>';
}

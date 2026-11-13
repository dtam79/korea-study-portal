<?php
defined('ABSPATH') || exit;

final class KGPC_Public {
    public static function routes(): void {
        add_rewrite_rule('^(ko|en)/guides/([a-z0-9-]+)/?$','index.php?kgpc_view=guide&kgpc_lang=$matches[1]&kgpc_slug=$matches[2]','top');
        add_rewrite_rule('^(ko|en)/guides/?$','index.php?kgpc_view=guides&kgpc_lang=$matches[1]','top');
        add_rewrite_rule('^(ko|en)/faq/?$','index.php?kgpc_view=faq&kgpc_lang=$matches[1]','top');
        add_rewrite_rule('^(ko|en)/(privacy|terms|about)/?$','index.php?kgpc_view=policy&kgpc_lang=$matches[1]&kgpc_slug=$matches[2]','top');
        add_rewrite_rule('^(ko|en)/policy/(privacy|terms|about)/?$','index.php?kgpc_view=policy&kgpc_lang=$matches[1]&kgpc_slug=$matches[2]','top');
        add_rewrite_rule('^(ko|en)/policy/?$','index.php?kgpc_view=policies&kgpc_lang=$matches[1]','top');
    }
    public static function query_vars(array $vars): array {return array_merge($vars,['kgpc_view','kgpc_lang','kgpc_slug']);}
    public static function ours(): bool {return in_array(get_query_var('kgpc_view'),['guides','guide','faq','policies','policy'],true);}
    public static function lang(): string {return get_query_var('kgpc_lang')==='en'?'en':'ko';}
    public static function page_title($old): string {
        if (!self::ours()) return (string)$old;
        $view=get_query_var('kgpc_view');$lang=self::lang();$slug=(string)get_query_var('kgpc_slug');
        $title=match($view){'guides','guide'=>self::text('guides',$lang),'faq'=>self::text('faq',$lang),'policies','policy'=>self::text('policies',$lang),default=>'Study in Korea'};
        if (in_array($view,['guide','policy'],true)) {
            $article=self::choose(self::published(),$view==='guide'?'guide':'policy',$lang,$slug);
            if ($article)$title=$article['title'];
        }
        return $title.' | Korea University Study Portal';
    }
    public static function url(string $lang,string $path): string {return home_url('/'.($lang==='en'?'en':'ko').'/'.ltrim($path,'/'));}
    public static function disable_canonical($url) {return self::ours()?false:$url;}
    public static function disable_404($preempt) {return self::ours()?true:$preempt;}
    public static function template(string $file): string {return self::ours()?KGPC_DIR.'templates/public.php':$file;}
    public static function nocache(): void {
        if (self::ours()) {
            if (!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            nocache_headers();
        }
    }
    public static function assets(): void {
        if (!self::ours())return;
        $css=get_stylesheet_directory().'/assets/css/site.css';
        if (is_file($css))wp_enqueue_style('kgpc-shared-fe',get_stylesheet_directory_uri().'/assets/css/site.css',[],(string)filemtime($css));
        wp_enqueue_style('kgpc-content',plugins_url('assets/public.css',KGPC_DIR.'kg-portal-content.php'),[],KGPC_VERSION);
    }
    public static function text(string $key,string $lang): string {
        $dict=[
            'guides'=>['한국 유학 가이드','Study in Korea guides'],
            'intro'=>['비자·체류·생활 안내를 공식 공개 자료에 근거해 확인하세요.','Understand visas, residency and student life using published official sources.'],
            'faq'=>['자주 묻는 질문','Frequently asked questions'],
            'faq_intro'=>['입학·비용·일정·생활·포털 이용 관련 질문입니다.','Questions about admissions, costs, schedules, living and using this portal.'],
            'policies'=>['정책과 운영 안내','Policies and portal information'],
            'empty'=>['게시된 안내가 없습니다.','No approved guidance is published yet.'],
            'pending'=>['게시된 내용이 없습니다. 공식 출처 확인·검토 완료 후 제공됩니다.','No content has been approved. Information will appear after source review.'],
            'unavailable'=>['이용할 수 없는 안내입니다.','This information is not available.'],
            'translation'=>['영어 번역이 아직 승인되지 않아 최신 한국어 원문을 표시합니다.','An approved English translation is not available. Showing the latest approved Korean original.'],
            'source'=>['공식 출처 및 확인 정보','Official sources and verification'],
            'checked'=>['원문 확인일','Source checked on'],
            'basis'=>['기준 시점','Reference date'],
            'audience'=>['적용 대상','Applies to'],
            'locator'=>['원문 위치','Source location'],
            'date_not_given'=>['공식 원문에 기준일 미기재','No reference date stated in official source'],
            'related'=>['다른 유학 가이드','Related guides'],
            'toc'=>['이 페이지의 목차','On this page'],
            'contact'=>['문의하기','Contact'],
            'university'=>['대학 찾기','Find universities'],
            'see'=>['안내 보기','Read guidance'],
            'back'=>['가이드 목록','All guides'],
            'effective'=>['적용일','Effective date'],
            'revised'=>['개정일','Last revised'],
            'operator'=>['운영 연락처','Operator contact'],
            'scope'=>['이용자가 실제 지원하는 대학의 공고를 우선 확인하세요.','Always check the official notice from the university where you intend to apply.'],
        ];
        $pair=$dict[$key]??[$key,$key];return $pair[$lang==='en'?1:0];
    }
    /** Read ONLY frozen, reviewer-approved snapshots; never read live WP drafts. */
    public static function published(): array {
        if (!KGPC_Content::ready())return [];
        global $wpdb;$p=$wpdb->posts;$m=$wpdb->postmeta;
        $ids=$wpdb->get_col("SELECT m.post_id FROM $m m JOIN $p p ON p.ID=m.post_id WHERE m.meta_key='_kgpc_active_version' AND p.post_type='kgpc_article' ORDER BY m.post_id ASC LIMIT 1000");
        $out=[];
        foreach ($ids?:[] as $id) {
            $snap=KGPC_Review::active((int)$id);
            if ($snap && KGPC_Review::is_current($snap))$out[]=$snap;
        }
        return $out;
    }
    public static function choose(array $items,string $kind,string $lang,string $slug): ?array {
        $target=null;$ko=null;
        foreach ($items as $item) {
            if ($item['kind']!==$kind||$item['slug']!==$slug)continue;
            if ($item['lang']===$lang)$target=$item;
            elseif ($item['lang']==='ko')$ko=$item;
        }
        if ($target)return $target;
        if ($lang==='en'&&$ko) {$ko['_fallback']=true;return $ko;}
        return null;
    }
    public static function preview_html(array $snap): string {
        $html=wp_kses_post(do_blocks((string)$snap['content']));
        // Keep a visible, structured TOC for headings, without user-provided IDs or JavaScript.
        $headings=[];$i=0;
        $html=preg_replace_callback('/<h([23])(?:\s[^>]*)?>(.*?)<\/h\1>/is',static function($m)use(&$headings,&$i){
            $i++;$id='section-'.$i;
            $label=wp_strip_all_tags($m[2]);
            $headings[]=['id'=>$id,'label'=>$label];
            return '<h'.(int)$m[1].' id="'.$id.'">'.$m[2].'</h'.(int)$m[1].'>';
        },$html);
        if ($headings) {
            $lang=self::lang();
            $nav='<nav class="kgpc-toc" aria-label="'.esc_attr(self::text('toc',$lang)).'"><strong>'.esc_html(self::text('toc',$lang)).'</strong><ol>';
            foreach ($headings as $h)$nav.='<li><a href="#'.esc_attr($h['id']).'">'.esc_html($h['label']).'</a></li>';
            $nav.='</ol></nav>';
            return $nav.'<div class="kgpc-body">'.$html.'</div>';
        }
        return '<div class="kgpc-body">'.$html.'</div>';
    }
    public static function sources(array $snap,string $lang): string {
        $ids=$snap['source_ids']??[];
        if (!$ids)return '';
        $html='<section class="kgpc-sources"><h2>'.esc_html(self::text('source',$lang)).'</h2><ul>';
        foreach ($ids as $id) {
            try {
                if (!KGP_Evidence_Repository::valid_source((int)$id))continue;
                $src=KGP_DB::require_row('sources',(int)$id);
                $url=esc_url((string)($src['public_url']??''),['https','http']);
                if (!$url)continue;
                $name=esc_html((string)($src['document_title']??'Official source'));
                $checked=esc_html((string)($src['checked_at']??''));
                $locator=esc_html((string)($snap['source_locator']??$src['document_locator']??''));
                $html.='<li><a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$name.' ↗</a>';
                if($checked)$html.='<small>'.esc_html(self::text('checked',$lang)).': '.$checked.'</small>';
                if($locator)$html.='<small>'.esc_html(self::text('locator',$lang)).': '.$locator.'</small>';
                $html.='</li>';
            } catch(Throwable $e) {continue;}
        }
        return $html.'</ul></section>';
    }
}

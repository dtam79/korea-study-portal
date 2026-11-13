<?php
defined('ABSPATH') || exit;
/** SEO only for already-approved public content. Private records are never serialized. */
final class KGPO_SEO {
    private const STATIC_PATHS=['','universities/','admissions/'];
    public static function routes(): void {
        add_rewrite_rule('^kgp-sitemap\\.xml$','index.php?kgpo_sitemap=1','top');
    }
    public static function vars(array $v): array {$v[]='kgpo_sitemap';return $v;}
    public static function staging(): bool {
        $host=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
        return wp_get_environment_type()!=='production' || str_ends_with($host,'.ddev.site') || in_array($host,['localhost','127.0.0.1'],true);
    }
    public static function core_sitemaps($enabled): bool {
        // Native post/page sitemaps can expose unrelated demo/blog content without
        // the project-level approval workflow. This approved-only sitemap replaces them.
        return false;
    }
    public static function robot_directives(array $robots): array {
        if (is_admin())return $robots;
        $route=self::public_route();
        $block=self::staging() || is_404() || (!$route && !self::is_sitemap()) || !empty($route['noindex']);
        if($block){unset($robots['index']);$robots['noindex']=true;$robots['follow']=true;}
        return $robots;
    }
    public static function is_sitemap(): bool {return (bool)get_query_var('kgpo_sitemap');}
    public static function no_404($preempt){return self::is_sitemap()?true:$preempt;}
    private static function row_xml(array $item): string {
        $e=static fn($x)=>htmlspecialchars((string)$x,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $out='<url><loc>'.$e($item['url']).'</loc>';
        if(!empty($item['lastmod']))$out.='<lastmod>'.$e($item['lastmod']).'</lastmod>';
        if(!empty($item['alternate'])&&is_array($item['alternate'])) {
            foreach($item['alternate'] as $lang=>$url){$out.='<xhtml:link rel="alternate" hreflang="'.$e($lang).'" href="'.$e($url).'" />';}
        }
        return $out.'</url>';
    }
    public static function published_universities(): array {
        if (!KGPO_Policy::core_ready() || !class_exists('KGP_Public_Catalog'))return [];
        global $wpdb;$t=KGP_DB::table('universities');
        $ids=$wpdb->get_col("SELECT id FROM $t ORDER BY id ASC LIMIT 3000");
        $rows=[];
        foreach ($ids?:[] as $id) {
            try {
                $entity=KGP_Identity_Repository::published('university',(int)$id);
                if (!$entity || empty($entity['public_id']))continue;
                $idValue=(string)$entity['public_id'];
                // Public catalog gates the complete relevant identity, not a mere DB record.
                $public=KGP_Public_Catalog::university($idValue,'ko');
                if (!$public || empty($public['offerings']))continue;
                $rows[]=['id'=>$idValue,'name_ko'=>(string)($entity['official_name_ko']??''),
                    'name_en'=>(string)($entity['official_name_en']??'')];
            }catch(Throwable $e){continue;}
        }
        return $rows;
    }
    public static function active_content(): array {
        if(!class_exists('KGPC_Public') || !class_exists('KGPC_Content') || !KGPC_Content::ready())return [];
        return KGPC_Public::published();
    }
    public static function entries(): array {
        if (self::staging())return [];
        $rows=[];
        foreach(['ko','en'] as $lang) {
            foreach(self::STATIC_PATHS as $path) {
                $loc=home_url('/'.$lang.'/'. $path);
                $alternates=['ko'=>home_url('/ko/'.$path),'en'=>home_url('/en/'.$path)];
                $rows[]=['url'=>$loc,'alternate'=>$alternates];
            }
        }
        if (class_exists('KGPC_Public') && class_exists('KGPC_Content') && KGPC_Content::ready()) {
            $items=self::active_content();
            $kinds=['guides/'=>'guide','faq/'=>'faq','policy/'=>'policy'];
            foreach(['ko','en'] as $lang){
                foreach($kinds as $path=>$kind){
                    if(!array_filter($items,static fn($v)=>$v['kind']===$kind&&$v['lang']===$lang))continue;
                    $alternate=[];
                    foreach(['ko','en'] as $a)if(array_filter($items,static fn($v)=>$v['kind']===$kind&&$v['lang']===$a))$alternate[$a]=home_url('/'.$a.'/'.$path);
                    $rows[]=['url'=>home_url('/'.$lang.'/'.$path),'alternate'=>count($alternate)>1?$alternate:[]];
                }
            }
            $approved=[];
            foreach($items as $item) {
                if(!in_array($item['kind'],['guide','policy'],true))continue; // FAQ has index-only route.
                if (!in_array($item['lang'],['ko','en'],true))continue;
                $key=$item['kind'].'|'.$item['slug'];$approved[$key][$item['lang']]=$item;
            }
            foreach($approved as $translations) {
                foreach($translations as $lang=>$item){
                    $path=$item['kind']==='guide'?'guides/'.$item['slug'].'/':$item['slug'].'/';
                    $alternates=[];
                    foreach($translations as $other_lang=>$other)$alternates[$other_lang]=home_url('/'.$other_lang.'/'.$path);
                    $rows[]=['url'=>home_url('/'.$lang.'/'.$path),'alternate'=>count($alternates)>1?$alternates:[],
                        'lastmod'=>!empty($item['approved_at'])?substr(str_replace(' ','T',$item['approved_at']),0,10):null];
                }
            }
        }
        if (class_exists('KGP_Operator_Contact_Pages')) {
            foreach(['ko','en'] as $lang)$rows[]=['url'=>home_url('/'.$lang.'/contact/'),
                'alternate'=>['ko'=>home_url('/ko/contact/'),'en'=>home_url('/en/contact/')]];
        }
        foreach(self::published_universities() as $item) {
            $path='universities/'.rawurlencode($item['id']).'/';
            foreach(['ko','en'] as $lang){$rows[]=['url'=>home_url('/'.$lang.'/'.$path),
                'alternate'=>['ko'=>home_url('/ko/'.$path),'en'=>home_url('/en/'.$path)]];}
        }
        // Deduplicate canonical path; no query/filter variants, drafts, inquiries or admin URLs.
        $out=[];foreach($rows as $row)$out[$row['url']]=$row;
        return array_values($out);
    }
    public static function serve_sitemap(): void {
        if(!self::is_sitemap())return;
        if(self::staging()){
            status_header(404);nocache_headers();exit;
        }
        status_header(200);header('Content-Type: application/xml; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');nocache_headers();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach(self::entries() as $row)echo self::row_xml($row) . "\n";
        echo '</urlset>';exit;
    }
    public static function robots(string $output,bool $public): string {
        if (self::staging())return "User-agent: *\nDisallow: /\n";
        return $output."\nSitemap: ".home_url('/kgp-sitemap.xml')."\n";
    }
    private static function public_route(): ?array {
        // Content plugin emits its own canonical, hreflang and fallback noindex.
        if (class_exists('KGPC_Public') && KGPC_Public::ours())return ['type'=>'content'];
        if (class_exists('KGP_Operator_Contact_Pages') && KGP_Operator_Contact_Pages::is_ours())return [
            'type'=>'contact','lang'=>KGP_Operator_Contact_Pages::language(),'path'=>'contact/',
            'noindex'=>(bool)get_query_var('kgpa_contact_done')];
        $screen=(string)get_query_var('kgp_screen');$lang=get_query_var('kgp_lang')==='en'?'en':'ko';
        if(!in_array($screen,['home','search','university','admissions'],true))return null;
        $path=match($screen){'home'=>'','search'=>'universities/','admissions'=>'admissions/',
            'university'=>'universities/'.rawurlencode((string)get_query_var('kgp_uni')).'/',default=>''};
        $query=wp_unslash($_GET); // Query filtering/pagination is not an indexable canonical page.
        $filtered=$screen==='search' && count($query)>0;
        $variant=$screen==='university' && !empty($query);
        $unpublished=false;
        if($screen==='university') {
            $public_id=(string)get_query_var('kgp_uni');
            if(!preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$public_id) || !class_exists('KGP_Public_Catalog'))$unpublished=true;
            else {
                try{$uni=KGP_Public_Catalog::university($public_id,$lang);$unpublished=!is_array($uni)||empty($uni);}
                catch(Throwable $e){$unpublished=true;}
            }
        }
        return ['type'=>'fe','lang'=>$lang,'path'=>$path,'noindex'=>$filtered||$variant||$unpublished,
            'unpublished'=>$unpublished];
    }
    public static function cache_headers(): void {
        $route=self::public_route();if(!$route)return;
        if (KGPO_Policy::get()['strict_no_cache'] || is_404() || !empty($route['noindex']) || $route['type']==='contact' || $route['type']==='content') {
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            nocache_headers();
        }else {
            // Only use this after the reverse proxy/WordPress cache invalidation is verified.
            header('Cache-Control: public, max-age=30, s-maxage=30, must-revalidate');
        }
    }
    public static function head(): void {
        $route=self::public_route();if(!$route || $route['type']==='content')return;
        $staging=self::staging();
        // WordPress' wp_robots hook emits noindex; avoid duplicate meta robots tags.
        if(($route['type']==='contact' && !empty($route['noindex'])) || !empty($route['unpublished']))return;
        $path=$route['path'];$canonicalPath=$path;
        if(($route['type']==='fe' && $path==='universities/') || ($route['type']==='fe' && str_starts_with($path,'universities/'))) {
            // No filters, offering queries or pagination in the canonical URL.
            $canonicalPath=$path;
        }
        $lang=$route['lang'];
        $canonical=home_url('/'.$lang.'/'.$canonicalPath);
        echo '<link rel="canonical" href="'.esc_url($canonical).'">' . "\n";
        if(!$route['noindex']) {
            foreach(['ko','en'] as $l)echo '<link rel="alternate" hreflang="'.$l.'" href="'.esc_url(home_url('/'.$l.'/'.$canonicalPath)).'">' . "\n";
        }
        $text=$lang==='ko'?'공식 출처로 확인된 한국 대학 입학·전공·모집 정보 안내':'Explore reviewed Korean university programs, requirements and official admissions links.';
        echo '<meta name="description" content="'.esc_attr($text).'">' . "\n";
    }
    public static function purge(): array {
        if (!KGPO_Policy::can_manage())throw new DomainException('Portal manager permission required.');
        $cache=wp_cache_flush();
        // This does not claim to clear external CDN, Redis, or WP-Optimize page-cache files.
        return ['wordpress_object_cache_cleared'=>(bool)$cache,'external_page_cache_cleared'=>false,
            'message'=>'WordPress object cache was flushed. Purge WP-Optimize/CDN separately if enabled.'];
    }
}

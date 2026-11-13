<?php
defined('ABSPATH') || exit;
/** Private operation controls; public SEO is served separately without private rows. */
final class KGPO_REST {
    private const NS='kgpo/v1';
    private static function add(string $route,string $method,callable $callback,string $role='review'): void {
        register_rest_route(self::NS,$route,[
            'methods'=>$method,
            'permission_callback'=>static fn()=>is_user_logged_in() && ($role==='manage'?KGPO_Policy::can_manage():KGPO_Policy::can_view()),
            'callback'=>static function(WP_REST_Request $r)use($callback){
                try {
                    if(!KGPO_Schema::ready())return new WP_Error('kgpo_schema_required','Run Operations schema installation as admin.',['status'=>503]);
                    return rest_ensure_response($callback($r));
                }catch(InvalidArgumentException $e){return new WP_Error('kgpo_invalid',$e->getMessage(),['status'=>422]);}
                catch(DomainException $e){return new WP_Error('kgpo_forbidden',$e->getMessage(),['status'=>403]);}
                catch(Throwable $e){
                    error_log('[KGPO private API] '.$e->getMessage());
                    return new WP_Error('kgpo_internal','Operation failed; check server logs.',['status'=>500]);
                }
            },
        ]);
    }
    private static function payload(WP_REST_Request $r): array {
        $data=$r->get_json_params();
        if (!is_array($data)||array_is_list($data))throw new InvalidArgumentException('Expected a JSON object.');
        return $data;
    }
    private static function number($val,string $name,int $max=10000000): int {
        $v=filter_var($val,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]);
        if($v===false)throw new InvalidArgumentException('Invalid '.$name.'.');return (int)$v;
    }
    public static function register(): void {
        self::add('/overview','GET',static function(){return [
            'counts'=>KGPO_Monitor::counts(),'settings'=>KGPO_Policy::get(),
            'core_ready'=>KGPO_Policy::core_ready(),'schema_ready'=>KGPO_Schema::ready(),
            'wp_cron_next'=>wp_next_scheduled('kgpo_scan_due'),
            'environment'=>wp_get_environment_type(),
            'staging_noindex'=>KGPO_SEO::staging(),
        ];});
        self::add('/sources','GET',static fn($r)=>KGPO_Monitor::list_sources(
            self::number($r->get_param('page')??1,'page',100000),
            (string)($r->get_param('filter')??'')));
        self::add('/sources/(?P<id>\d+)/history','GET',static fn($r)=>KGPO_Monitor::history(self::number($r['id'],'source ID')));
        self::add('/sources/(?P<id>\d+)/policy','POST',static fn($r)=>KGPO_Monitor::configure_source(
            self::number($r['id'],'source ID'),self::payload($r)),'manage');
        self::add('/sources/(?P<id>\d+)/check','POST',static fn($r)=>KGPO_Monitor::check(self::number($r['id'],'source ID'),true));
        self::add('/scan','POST',static function($r){$p=self::payload($r);
            $limit=self::number($p['limit']??KGPO_Policy::get()['batch_size'],'limit',25);
            return KGPO_Monitor::due($limit);
        },'manage');
        self::add('/tasks','GET',static fn($r)=>KGPO_Monitor::list_tasks(
            self::number($r->get_param('page')??1,'page',100000),
            (string)($r->get_param('status')??'pending')));
        self::add('/tasks/(?P<id>\d+)/review','POST',static function($r){
            $p=self::payload($r);
            $status=(string)($p['status']??'');$note=(string)($p['note']??'');$resolution=(string)($p['resolution']??'');
            return KGPO_Monitor::decide(self::number($r['id'],'task ID'),$status,$note,$resolution);
        });
        self::add('/settings','GET',static fn()=>KGPO_Policy::get(),'manage');
        self::add('/settings','POST',static fn($r)=>KGPO_Policy::update(self::payload($r)),'manage');
        self::add('/seo/preview','GET',static fn()=>[
            'staging'=>KGPO_SEO::staging(), 'sitemap_url'=>home_url('/kgp-sitemap.xml'),
            'routes'=>['/ko/','/en/','/ko/universities/','/en/universities/','/ko/admissions/','/en/admissions/'],
            'published_universities'=>count(KGPO_SEO::published_universities()),
            'published_articles'=>count(KGPO_SEO::active_content()),
            'recommendation'=>'Run approved-only sitemap on a production URL; staging never emits it.',
        ]);
        self::add('/cache/purge','POST',static fn()=>KGPO_SEO::purge(),'manage');
    }
}

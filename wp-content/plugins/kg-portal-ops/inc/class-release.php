<?php
/**
 * W3-2: read-only release audit. This is an automated readiness check, NOT
 * proof of independent source review, accessibility, load or DB restoration.
 */
defined('ABSPATH') || exit;

final class KGPO_Release {
    public const PRIVATE_ROUTES = [
        '/kgp/v1/admin/summary',
        '/kgp-operator/v1/catalog',
        '/kgpo/v1/overview',
    ];
    public const REQUIRED_PLUGINS = [
        'kg-portal-core/kg-portal-core.php',
        'kg-portal-admin/kg-portal-admin.php',
        'kg-portal-content/kg-portal-content.php',
        'kg-portal-design/kg-portal-design.php',
        'kg-portal-ops/kg-portal-ops.php',
    ];
    public const MANUAL_GATES = [
        'source_verification_20' => 'Independent official-source, offering and applicability review for 20 real universities',
        'browser_accessibility' => 'KO/EN P01-P10 and A01-A09 UAT, keyboard and 320-1440px/200% zoom',
        'isolated_restore' => 'Restore database AND uploads on a separate isolated environment',
        'load_and_cache' => 'Staging 2,000 offerings x 20 concurrent reads, p95 and post-approval cache propagation',
        'operations' => 'External daily backup retention, system cron, email, incident and plugin-update drill',
        'operator_signoff' => 'Named operator sign-off, legal/privacy review and zero critical/high issues',
    ];

    private static function put(array &$out, string $code, bool $passes, string $message, $actual = null): void {
        $out[] = ['id' => $code, 'status' => $passes ? 'pass' : 'fail', 'message' => $message, 'actual' => $actual];
    }
    /**
     * Deterministic and independently unit-testable release checks.
     * Missing/unavailable data fails closed; no synthetic seed counts as real data.
     */
    public static function evaluate(array $facts, string $mode = 'staging', int $min_universities = 20, int $min_offerings = 20): array {
        if (!in_array($mode, ['local','staging','production'], true)) throw new InvalidArgumentException('mode must be local, staging or production.');
        if ($min_universities < 1 || $min_universities > 1000 || $min_offerings < 1 || $min_offerings > 20000) {
            throw new InvalidArgumentException('Count requirements outside allowed bounds.');
        }
        $site = $facts['site'] ?? [];
        $system = $facts['system'] ?? [];
        $content = $facts['content'] ?? [];
        $security = $facts['security'] ?? [];
        $checks = [];
        self::put($checks,'schemas',($system['core_schema'] ?? false) === true && ($system['content_schema'] ?? false) === true && ($system['ops_schema'] ?? false) === true,
            'Core, Content and Operations database schemas are installed.', [
                'core' => $system['core_schema'] ?? false,
                'content' => $system['content_schema'] ?? false,
                'ops' => $system['ops_schema'] ?? false,
            ]);
        $plugins = $system['active_plugins'] ?? [];
        self::put($checks,'plugins',is_array($plugins) && !array_diff(self::REQUIRED_PLUGINS, $plugins),
            'All five custom WordPress plugins are active.', is_array($plugins) ? array_values(array_intersect(self::REQUIRED_PLUGINS,$plugins)) : []);
        $environment = $site['environment'] ?? null;
        $is_staging = $site['staging_noindex'] ?? null;
        $env_ok = $mode === 'production' ? ($environment === 'production' && $is_staging === false) : ($is_staging === true);
        self::put($checks,'environment',$env_ok,'Environment and approved-only sitemap staging gate match selected mode.', [
            'requested_mode'=>$mode,'runtime'=>$environment,'staging_noindex'=>$is_staging]);
        self::put($checks,'https',$site['https'] ?? false,'Public home URL uses HTTPS.', $site['scheme'] ?? null);
        self::put($checks,'indexing',$mode === 'production' ? ($site['indexable'] ?? false) === true : ($site['staging_sitemap_empty'] ?? false) === true,
            $mode === 'production' ? 'Production search indexing is enabled.' : 'Non-production approved-only sitemap is empty.',
            $mode === 'production' ? ($site['indexable'] ?? null) : ($site['staging_sitemap_empty'] ?? null));
        self::put($checks,'debug_display',$mode !== 'production' || ($site['debug_display'] ?? true) === false,
            'Production does not expose PHP diagnostics to visitors.', $site['debug_display'] ?? null);
        self::put($checks,'data_integrity',($system['integrity_ok'] ?? false) === true,
            'Core published field values, evidence and parent relationships pass full read-only audit.', $system['integrity_issues'] ?? null);
        self::put($checks,'cron_scheduled',($system['cron_scheduled'] ?? false) === true,
            'Official-source review cron hook is scheduled.', $system['cron_scheduled'] ?? null);
        self::put($checks,'source_monitor',($system['monitor_enabled'] ?? false) === true,
            'Scheduled official-source monitor is enabled.', $system['monitor_enabled'] ?? null);
        self::put($checks,'pending_reviews',isset($system['open_review_tasks']) && $system['open_review_tasks'] === 0,
            'No unresolved source-monitor review tasks.', $system['open_review_tasks'] ?? null);
        self::put($checks,'safe_cache',($system['strict_no_cache'] ?? false) === true,
            'Strict no-cache stays on until 60-second invalidation is independently proven.', $system['strict_no_cache'] ?? null);
        $universities = $content['published_universities'] ?? null;
        self::put($checks,'universities',is_int($universities) && $universities >= $min_universities,
            'Minimum approved university identities with a publicly valid Offering.', ['count'=>$universities,'minimum'=>$min_universities]);
        $ko = $content['approved_offerings_ko'] ?? null;
        $en = $content['approved_offerings_en'] ?? null;
        self::put($checks,'offerings',is_int($ko) && is_int($en) && $ko >= $min_offerings && $en >= $min_offerings && $ko === $en,
            'Both locales expose identical approved Offering counts above the minimum.', ['ko'=>$ko,'en'=>$en,'minimum'=>$min_offerings]);
        self::put($checks,'pilot',($content['pilot_ready'] ?? false) === true,
            'Two existing published universities pass KO/EN Offering and approved-source parity.', $content['pilot_ready'] ?? null);
        foreach (['ko','en'] as $lang) {
            $languages = $content['language_topics'][$lang] ?? [];
            foreach (['guides'=>6, 'faq_categories'=>5, 'policies'=>3] as $key=>$need) {
                $count = $languages[$key] ?? null;
                self::put($checks,'content_'.$lang.'_'.$key,is_int($count) && $count >= $need,
                    'Approved and current '.$lang.' '.$key.' cover the required topics.', ['count'=>$count,'minimum'=>$need]);
            }
        }
        $private = $security['private_statuses'] ?? [];
        $all_private = is_array($private) && count($private) === count(self::PRIVATE_ROUTES);
        foreach (self::PRIVATE_ROUTES as $route) {
            $status = $private[$route] ?? null;
            if (!in_array($status,[401,403],true)) $all_private = false;
        }
        self::put($checks,'anonymous_private',$all_private,'All registered private GET endpoints reject anonymous requests with HTTP 401/403.', $private);
        $public = $security['public_statuses'] ?? [];
        self::put($checks,'anonymous_public',is_array($public) && ($public['ko'] ?? 0) === 200 && ($public['en'] ?? 0) === 200,
            'Both language catalog REST endpoints accept anonymous read requests.', $public);
        $failed = array_values(array_map(static fn($v)=>$v['id'],array_filter($checks,static fn($v)=>$v['status']==='fail')));
        return [
            'mode'=>$mode,
            'automated_ready'=>count($failed)===0,
            'release_certified'=>false,
            'failed_checks'=>$failed,
            'checks'=>$checks,
            'manual_pending'=>self::MANUAL_GATES,
            'note'=>'Automated checks do not certify 20 original sources, browser QA, staging load, isolated restore or human signoff.',
        ];
    }

    /** Build a report entirely from currently approved reads; no database writes. */
    public static function facts(): array {
        $environment = wp_get_environment_type();
        $url = home_url('/');
        $scheme = strtolower((string)wp_parse_url($url,PHP_URL_SCHEME));
        $site = [
            'environment'=>$environment,
            'scheme'=>$scheme,
            'https'=>$scheme==='https',
            'staging_noindex'=>KGPO_SEO::staging(),
            'staging_sitemap_empty'=>KGPO_SEO::staging() ? KGPO_SEO::entries() === [] : null,
            'indexable'=>(string)get_option('blog_public','1')==='1',
            'debug_display'=>defined('WP_DEBUG_DISPLAY') ? (bool)WP_DEBUG_DISPLAY : (bool)filter_var(ini_get('display_errors'),FILTER_VALIDATE_BOOLEAN),
        ];
        $active = get_option('active_plugins',[]);
        if (!is_array($active)) $active=[];
        if (is_multisite() && function_exists('get_site_option')) {
            $network=get_site_option('active_sitewide_plugins',[]);
            if(is_array($network))$active=array_merge($active,array_keys($network));
        }
        $core = class_exists('KGP_Schema') && KGP_Schema::status()['ok'];
        $ops = KGPO_Schema::ready();
        $content = class_exists('KGPC_Content') && KGPC_Content::ready();
        $integrity = false;$integrity_issues = null;
        if ($core && class_exists('KGP_Integrity_Audit')) {
            try {
                $report=KGP_Integrity_Audit::scan(20000);
                $integrity_issues=count($report['issues']);
                $integrity = $integrity_issues===0;
            } catch(Throwable $e) {
                $integrity_issues='Audit incomplete: '.$e->getMessage();
            }
        }
        $settings=KGPO_Policy::get();
        $open=null;
        if ($ops) {
            global $wpdb;
            $table=KGPO_Schema::table('review_tasks');
            $count=$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status_code IN ('pending','in_progress')");
            if (!$wpdb->last_error && $count!==null) $open=(int)$count;
        }
        $system = [
            'core_schema'=>(bool)$core,'ops_schema'=>(bool)$ops,'content_schema'=>(bool)$content,
            'active_plugins'=>$active,'integrity_ok'=>$integrity,'integrity_issues'=>$integrity_issues,
            'cron_scheduled'=>wp_next_scheduled('kgpo_scan_due')!==false,
            'monitor_enabled'=>($settings['monitor_enabled']??false)===true,
            'open_review_tasks'=>$open,
            'strict_no_cache'=>($settings['strict_no_cache']??false)===true,
        ];
        $published=[];
        if ($core && class_exists('KGP_Public_Catalog')) {
            $published=KGPO_SEO::published_universities();
        }
        $numbers=['ko'=>null,'en'=>null];
        if ($core && class_exists('KGP_Public_Catalog')) {
            foreach (['ko','en'] as $lang) {
                try {$numbers[$lang]=(int)KGP_Public_Catalog::search(['locale'=>$lang,'page'=>1])['total'];}
                catch(Throwable $e) {$numbers[$lang]=null;}
            }
        }
        $pilot=null;
        if (count($published)>=2 && class_exists('KGP_Pilot_Readiness')) {
            try {
                $r=KGP_Pilot_Readiness::check([(string)$published[0]['id'],(string)$published[1]['id']]);
                $pilot=($r['technically_ready']??false)===true;
            } catch(Throwable $e) { $pilot=false; }
        }
        $language_topics = [
            'ko'=>['guides'=>0,'faq_categories'=>0,'policies'=>0],
            'en'=>['guides'=>0,'faq_categories'=>0,'policies'=>0],
        ];
        if ($content && class_exists('KGPC_Public')) {
            $all=['ko'=>['guides'=>[],'faq_categories'=>[],'policies'=>[]],
                  'en'=>['guides'=>[],'faq_categories'=>[],'policies'=>[]]];
            foreach (KGPC_Public::published() as $row) {
                $lang=$row['lang']??''; $kind=$row['kind']??'';
                if (!isset($all[$lang])) continue;
                if ($kind==='guide' && isset(KGPC_Content::TOPICS[$row['topic']??''])) $all[$lang]['guides'][$row['topic']]=true;
                elseif ($kind==='faq' && isset(KGPC_Content::FAQ_CATS[$row['category']??''])) $all[$lang]['faq_categories'][$row['category']]=true;
                elseif ($kind==='policy' && isset(KGPC_Content::POLICIES[$row['slug']??''])) $all[$lang]['policies'][$row['slug']]=true;
            }
            foreach ($all as $lang=>$groups) foreach ($groups as $key=>$values) $language_topics[$lang][$key]=count($values);
        }
        $security=['private_statuses'=>[],'public_statuses'=>[]];
        if (function_exists('rest_get_server') && function_exists('rest_do_request')) {
            $routes=rest_get_server()->get_routes();
            $previous=get_current_user_id();
            wp_set_current_user(0);
            try {
                foreach (self::PRIVATE_ROUTES as $path) {
                    if (!isset($routes[$path])) {$security['private_statuses'][$path]=0;continue;}
                    $response=rest_do_request(new WP_REST_Request('GET',$path));
                    $security['private_statuses'][$path]=(int)$response->get_status();
                }
                foreach (['ko','en'] as $locale) {
                    $path='/kgp/v1/catalog';
                    if (!isset($routes[$path])) {$security['public_statuses'][$locale]=0;continue;}
                    $request=new WP_REST_Request('GET',$path);$request->set_param('locale',$locale);
                    $response=rest_do_request($request);
                    $security['public_statuses'][$locale]=(int)$response->get_status();
                }
            } finally {wp_set_current_user($previous);}
        }
        return [
            'site'=>$site,'system'=>$system,
            'content'=>['published_universities'=>count($published),
                'approved_offerings_ko'=>$numbers['ko'],'approved_offerings_en'=>$numbers['en'],
                'pilot_ready'=>$pilot,'language_topics'=>$language_topics],
            'security'=>$security,
        ];
    }
}

/** CLI command is visible only to an explicitly selected administrator. */
final class KGPO_Release_Command {
    /**
     * Audit the current site before attempting a launch; no writes or deployments.
     *
     * ## OPTIONS
     *
     * [--mode=<local|staging|production>]
     * : Expected environment (default: staging). Production mode fails on DDEV.
     *
     * [--min-universities=<n>]
     * : Minimum approved universities (default: 20).
     *
     * [--min-offerings=<n>]
     * : Minimum approved offerings per language (default: 20).
     *
     * [--strict]
     * : Return nonzero when any *automated* check fails. Human signoff still required.
     *
     * @subcommand audit
     */
    public function audit(array $args, array $assoc_args): void {
        if (!current_user_can('manage_options')) WP_CLI::error('Administrator context required. Pass --user=<administrator-id>.');
        $mode=(string)($assoc_args['mode']??'staging');
        $minimums=[];
        foreach (['min-universities'=>1000,'min-offerings'=>20000] as $name=>$max) {
            $raw=(string)($assoc_args[$name]??'20');
            if (!ctype_digit($raw) || (int)$raw<1 || (int)$raw>$max) WP_CLI::error('Invalid --'.$name.'.');
            $minimums[$name]=(int)$raw;
        }
        try {$report=KGPO_Release::evaluate(KGPO_Release::facts(),$mode,$minimums['min-universities'],$minimums['min-offerings']);}
        catch(Throwable $e){WP_CLI::error('Read-only release audit failed: '.$e->getMessage());return;}
        WP_CLI::line(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (isset($assoc_args['strict']) && !$report['automated_ready']) {
            WP_CLI::error(count($report['failed_checks']).' automated launch blockers. No database changes.');
        }
        if ($report['automated_ready']) WP_CLI::warning('Automated checks passed; NOT launch-certified without manual QA and signoff.');
        else WP_CLI::warning(count($report['failed_checks']).' launch blockers found; NOT ready to publish.');
    }
}

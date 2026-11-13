<?php
defined('ABSPATH') || exit;
/** Server-side CLI scheduler. Run with a real system cron, not only low-traffic WP-Cron. */
final class KGPO_CLI {
    /** Inspect readiness, due count, tasks and last run. */
    public function status(): void {
        if (!KGPO_Schema::ready())WP_CLI::error('Operations schema missing. Run: wp eval "KGPO_Schema::install();"');
        if (!KGPO_Policy::core_ready())WP_CLI::error('KGP Core missing.');
        $data=['counts'=>KGPO_Monitor::counts_cli(),'settings'=>KGPO_Policy::get(),
            'wp_cron_next'=>wp_next_scheduled('kgpo_scan_due'),'staging_noindex'=>KGPO_SEO::staging()];
        WP_CLI::line(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }
    /** Run a bounded due-source scan. ## OPTIONS [--limit=<n>] (1..25; default configured batch size) */
    public function scan(array $args,array $assoc): void {
        if (!KGPO_Schema::ready())WP_CLI::error('Operations schema missing.');
        $n=isset($assoc['limit'])?(int)$assoc['limit']:(int)KGPO_Policy::get()['batch_size'];
        try{$result=KGPO_Monitor::due($n);WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));}
        catch(Throwable $e){WP_CLI::error($e->getMessage());}
    }
    /** Inspect one official source, manually. ## OPTIONS <id> */
    public function check(array $args,array $assoc): void {
        $id=(int)($args[0]??0);if($id<1)WP_CLI::error('Source ID required.');
        try{WP_CLI::line(wp_json_encode(KGPO_Monitor::check($id,true),JSON_PRETTY_PRINT));}
        catch(Throwable $e){WP_CLI::error($e->getMessage());}
    }
    /** Purge the WordPress object cache. This does NOT clear your CDN or WP-Optimize disk cache. */
    public function purge(): void {
        WP_CLI::line(wp_cache_flush()?'WordPress object cache flushed. External page cache not touched.':'Object cache flush returned false.');
    }
}

<?php
defined('ABSPATH') || exit;
final class KGPO_Admin {
    private const PAGES=['kgpo-operations'=>'Operations','kgpo-sources'=>'Source monitoring','kgpo-review'=>'Review queue','kgpo-seo'=>'Cache & SEO'];
    public static function menu(): void {
        if(!KGPO_Policy::can_view())return;
        $cap=KGPO_Policy::can_manage()?'kgp_manage_assignments':'kgp_review_entries';
        add_menu_page('KGP Operations','Source monitoring',$cap,'kgpo-operations',[self::class,'render'],'dashicons-update',34);
        foreach(self::PAGES as $slug=>$label) {
            if($slug==='kgpo-seo' && !KGPO_Policy::can_manage())continue;
            add_submenu_page('kgpo-operations',$label,$label,$cap,$slug,[self::class,'render']);
        }
    }
    public static function enqueue(string $hook): void {
        $name=isset($_GET['page'])&&is_scalar($_GET['page'])?sanitize_key(wp_unslash((string)$_GET['page'])):'';
        if(!isset(self::PAGES[$name])||!KGPO_Policy::can_view())return;
        wp_enqueue_style('kgpo-operator',plugins_url('assets/operator.css',KGPO_DIR.'kg-portal-ops.php'),[],KGPO_VERSION);
        wp_enqueue_script('kgpo-operator',plugins_url('assets/operator.js',KGPO_DIR.'kg-portal-ops.php'),[],KGPO_VERSION,true);
        wp_add_inline_script('kgpo-operator','window.KGPO_ADMIN = '.wp_json_encode([
            'root'=>esc_url_raw(rest_url('kgpo/v1/')), 'nonce'=>wp_create_nonce('wp_rest'),
            'page'=>$name,'manager'=>KGPO_Policy::can_manage(), 'reviewer'=>current_user_can('kgp_review_entries'),
            'adminBase'=>admin_url('admin.php?page='), 'coreSourceUrl'=>admin_url('admin.php?page=kgp-operator-sources'),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';','before');
    }
    public static function render(): void {
        if(!KGPO_Policy::can_view())wp_die('Access denied','',['response'=>403]);
        echo '<div class="wrap kgpo-root-wrap"><h1>Study Portal · Official-source operations</h1>';
        if(!KGPO_Policy::core_ready()) echo '<div class="notice notice-error"><p>KG Portal Core must be installed and active before source monitoring.</p></div>';
        if(!KGPO_Schema::ready())echo '<div class="notice notice-error"><p>Operations tables are not installed. Run <code>ddev wp eval &quot;KGPO_Schema::install();&quot;</code>.</p></div>';
        echo '<div id="kgpo-dashboard"><p role="status">Loading operator dashboard…</p></div></div>';
    }
}

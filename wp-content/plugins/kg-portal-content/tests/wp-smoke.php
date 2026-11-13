<?php
/** Run: ddev wp --user=YOUR_ADMIN eval-file wp-content/plugins/kg-portal-content/tests/wp-smoke.php */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(1);
function kgpc_assert(bool $ok,string $msg):void {
    if (!$ok) WP_CLI::error('FAIL: '.$msg);
    WP_CLI::log('PASS: '.$msg);
}
kgpc_assert(KGPC_Content::ready(),'Core v0.4 + admin v0.8 and content v0.9 schema available');
kgpc_assert(current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries'),'An existing content/editor reviewer role is selected');
$type=get_post_type_object('kgpc_article');
kgpc_assert((bool)$type && $type->show_in_rest && !$type->publicly_queryable,'Private Gutenberg article type registered');
kgpc_assert(count(KGPC_Content::TOPICS)===6 && count(KGPC_Content::FAQ_CATS)===5,'Six guide topics and five FAQ categories registered');
$rules=get_option('rewrite_rules');
foreach(['guides','faq','policy','privacy','terms','about'] as $name) {
    kgpc_assert((bool)array_filter(array_keys((array)$rules),static fn($key)=>str_contains($key,$name)), 'Localized rewrite rule: '.$name);
}
kgpc_assert(is_file(get_stylesheet_directory().'/assets/css/site.css'),'Existing FE shared design stylesheet is present');
global $wpdb;
$table=KGPC_Content::table();
kgpc_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table,'Versioned public snapshot table exists');
$user=get_current_user_id();wp_set_current_user(0);
try {
    $caps=['edit_posts'=>$type->cap->edit_posts,'publish_posts'=>$type->cap->publish_posts];
    kgpc_assert(!current_user_can($caps['edit_posts'])&&!current_user_can($caps['publish_posts']),'Anonymous cannot edit or publish');
} finally {wp_set_current_user($user);}
kgpc_assert(KGPC_Content::prevent_native_publication(['post_type'=>'kgpc_article','post_status'=>'publish'],[])['post_status']==='draft','Native WP publish button does not bypass review');
WP_CLI::success('P05–P07/P10 integration smoke passed. No database rows changed.');

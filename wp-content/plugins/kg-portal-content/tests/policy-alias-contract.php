<?php
/** Offline route contract. Both canonical paths read the same approved snapshot, no publication bypass. */
declare(strict_types=1);
define('ABSPATH', '/tmp/');
$rules=[];
function add_rewrite_rule($regex,$query,$where){global $rules;$rules[$regex]=[$query,$where];}
require dirname(__DIR__) . '/includes/class-public.php';
KGPC_Public::routes();
function verify_route(string $uri, string $expected):void {
    global $rules;
    foreach ($rules as $pattern=>$item) {
        if (preg_match('~'.$pattern.'~',ltrim($uri,'/'),$matches)) {
            $actual=$item[0];
            foreach ($matches as $key=>$value)if(is_int($key))$actual=str_replace('$matches['.$key.']',$value,$actual);
            if ($actual!==$expected)throw new RuntimeException('Unexpected route for '.$uri.': '.$actual);
            return;
        }
    }
    throw new RuntimeException('Missing route for '.$uri);
}
foreach (['ko','en'] as $lang){
    foreach (['privacy','terms','about'] as $slug){
        $target='index.php?kgpc_view=policy&kgpc_lang='.$lang.'&kgpc_slug='.$slug;
        verify_route($lang.'/'.$slug.'/',$target);
        verify_route($lang.'/policy/'.$slug.'/',$target);
        verify_route($lang.'/policies/'.$slug.'/',$target);
    }
    verify_route($lang.'/policy/','index.php?kgpc_view=policies&kgpc_lang='.$lang);
    verify_route($lang.'/policies/','index.php?kgpc_view=policies&kgpc_lang='.$lang);
}
$php=file_get_contents(dirname(__DIR__).'/templates/public.php');
if (!str_contains($php, 'KGPC_Public::published()') || !str_contains($php, 'if ($empty)status_header(404)')) {
    throw new RuntimeException('Policy aliases must still honor approved-only and 404 behavior');
}
echo "PASS: KO/EN policy URL aliases share approved-only resolver and do not publish drafts.\n";

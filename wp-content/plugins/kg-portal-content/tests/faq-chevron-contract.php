<?php
/** The FAQ must have exactly one visual disclosure marker; no WP/DB needed. */
declare(strict_types=1);
$root=dirname(__DIR__);
$template=(string) file_get_contents($root.'/templates/public.php');
$content=(string) file_get_contents($root.'/assets/public.css');
$design=(string) file_get_contents($root.'/../kg-portal-design/assets/refresh.css');
if (!str_contains($template,'<details class="kgpc-faq"><summary><span class="kgpc-faq-question">')) throw new RuntimeException('FAQ summary label missing');
if (str_contains($template,'class="kgpc-expand"') || str_contains($template,'>＋</span>')) throw new RuntimeException('Duplicate FAQ plus icon remains');
if (!str_contains($content,'.kgpc-faq>summary::after') || !str_contains($design,'.kgpc-faq > summary::after')) throw new RuntimeException('One shared disclosure arrow is missing');
if (!str_contains($template,"KGPC_Public::sources(\$faq,\$lang)")) throw new RuntimeException('Approved FAQ evidence output changed');
echo "PASS: FAQ has one CSS chevron; disclosure, original approved FAQ content, and sources are unchanged.\n";

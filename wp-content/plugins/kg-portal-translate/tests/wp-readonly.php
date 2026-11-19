<?php
/** Requires a loaded WP instance, invoked via wp eval 'require ...', never eval-file strict_types. */
if (!defined('ABSPATH') || !class_exists('KGPT_Translation') || !class_exists('KGPT_Gemini')) throw new RuntimeException('Translation plugin inactive.');
$routes=rest_get_server()->get_routes();
if (!array_key_exists('/kgpt/v1/translate',$routes)) throw new RuntimeException('Public translation endpoint not registered.');
if (!isset(KGPT_Translation::LOCALES['vi']) || KGPT_Translation::locale('zh-TW')!=='zh-TW') throw new RuntimeException('Locales contract broken.');
if (!str_starts_with(rest_url('kgpt/v1/translate'),'http')) throw new RuntimeException('REST route URL invalid.');
if (KGPT_Gemini::api_key()==='') echo "NOTE: GEMINI_API_KEY is not configured; KO/EN works but machine translation remains disabled.\n";
echo "PASS: WP translation plugin loaded, route registered, no provider call or data mutation.\n";

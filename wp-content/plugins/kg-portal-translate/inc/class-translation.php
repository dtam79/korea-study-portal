<?php
defined('ABSPATH') || exit;

/** Public-page text manifest; Gemini never sees a browser's freely submitted text or private form contents. */
final class KGPT_Translation {
    public const LOCALES = [
        'ko'=>'한국어', 'en'=>'English', 'ja'=>'日本語', 'zh-CN'=>'简体中文', 'zh-TW'=>'繁體中文',
        'vi'=>'Tiếng Việt', 'th'=>'ไทย', 'id'=>'Bahasa Indonesia', 'ms'=>'Bahasa Melayu',
        'mn'=>'Монгол', 'uz'=>'Oʻzbek', 'ru'=>'Русский', 'ne'=>'नेपाली', 'hi'=>'हिन्दी',
        'fr'=>'Français', 'de'=>'Deutsch', 'es'=>'Español', 'pt'=>'Português', 'ar'=>'العربية',
        'fa'=>'فارسی', 'bn'=>'বাংলা', 'ur'=>'اردو', 'tr'=>'Türkçe', 'my'=>'မြန်မာ',
        'kk'=>'Қазақша', 'km'=>'ខ្មែរ', 'lo'=>'ລາວ', 'si'=>'සිංහල',
    ];
    private const VOID = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
    private const OMIT = ['script','style','noscript','template','svg','math','code','pre','textarea','select','option','iframe'];
    private const MANIFEST_TTL = 604800; // One week. Fingerprint invalidates changes in approved public content.
    private const RESULT_TTL = 1209600; // Two weeks.

    public static function base_language(): string {
        foreach (['kgp_lang', 'kgpc_lang', 'kgpa_lang'] as $key) {
            $v = function_exists('get_query_var') ? get_query_var($key) : null;
            if ($v === 'en') return 'en';
            if ($v === 'ko') return 'ko';
        }
        return 'en';
    }
    public static function is_public(): bool {
        if (!function_exists('get_query_var')) return false;
        return (bool) (get_query_var('kgp_screen') || get_query_var('kgpc_view') || get_query_var('kgpa_contact') || get_query_var('kgpa_contact_done'));
    }
    public static function allowed_page(): bool {
        if (function_exists('is_admin') && is_admin()) return false;
        if ((defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) return false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
        if (!self::is_public() || (function_exists('get_query_var') && (get_query_var('kgpa_contact') || get_query_var('kgpa_contact_done')))) return false; // Never send contact form, consent or receipt text to Gemini.
        // Search query text, tracking parameters, or unforeseen request inputs may contain personal information.
        foreach (array_keys($_GET) as $key) {
            if (!in_array((string)$key, ['field','region','level','teaching_lang','cost_unit','tuition_min',
                'tuition_max','status','admission_status','certified','sort','page','paged','offering',
                'category','type','university','semester','year','campus','english_only','q','qsort'], true)) return false;
        }
        if (isset($_GET['q']) && !is_scalar($_GET['q'])) return false;
        return true;
    }
    public static function capture(): void {
        if (!self::allowed_page()) return;
        // Output buffering cannot affect approved data, HTML routes, SEO metadata or admin pages.
        ob_start([self::class, 'inject_manifest']);
    }
    public static function assets(): void {
        if (!self::is_public() || (function_exists('is_admin') && is_admin())) return;
        $url = plugin_dir_url(KGPT_DIR . 'kg-portal-translate.php');
        wp_enqueue_style('kgpt-public', $url . 'assets/auto-translate.css', [], KGPT_VERSION);
        wp_enqueue_script('kgpt-public', $url . 'assets/auto-translate.js', [], KGPT_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]);
    }
    public static function register_rest(): void {
        register_rest_route('kgpt/v1', '/translate', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'rest_translate'],
        ]);
    }
    public static function normalize(string $value): string {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $value));
    }
    public static function translate_candidate(string $value): bool {
        $len = strlen($value);
        if ($len < 2 || $len > 1100) return false;
        if (!preg_match('/\p{L}/u', $value)) return false;
        if (str_contains($value, '@') || preg_match('~https?://|www\.~i', $value)) return false;
        // Preserve dates, currency, rankings, counts and official identifiers verbatim.
        if (preg_match('/\d/u', $value)) return false;
        return true;
    }
    public static function locale(string $tag): ?string {
        $tag = str_replace('_', '-', trim($tag));
        if (isset(self::LOCALES[$tag])) return $tag;
        $lower = strtolower($tag);
        if (preg_match('/^zh-(hk|mo|tw|hant)(-|$)/', $lower)) return 'zh-TW';
        if (preg_match('/^zh/', $lower)) return 'zh-CN';
        $base = explode('-', $lower)[0];
        return isset(self::LOCALES[$base]) ? $base : null;
    }
    /** Determine which text is server-published; never trust text in a REST request. */
    public static function extract(string $html): array {
        $html = preg_replace('~<!--.*?-->~s', '', $html);
        $html = preg_replace('~<(script|style|noscript|template|svg)\b[^>]*>.*?</\1\s*>~is', '', $html);
        $parts = preg_split('~(<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)~s', (string)$html, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) return [];
        $stack = [];
        $seen = [];
        $total = 0;
        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                if (preg_match('/^<\s*\/\s*([a-z][\w:-]*)/i', $part, $m)) {
                    $close = strtolower($m[1]);
                    for ($i=count($stack)-1; $i>=0; $i--) {
                        if ($stack[$i]['name'] === $close) {$stack = array_slice($stack, 0, $i); break;}
                    }
                    continue;
                }
                if (!preg_match('/^<\s*([a-z][\w:-]*)/i', $part, $m)) continue;
                $tag = strtolower($m[1]);
                $parent = $stack ? $stack[count($stack)-1] : ['shell'=>false,'omit'=>false];
                $shell = $parent['shell'] || (bool)preg_match('/\bclass\s*=\s*["\'][^"\']*\bkgp-shell\b/is', $part);
                $omit = $parent['omit'] || in_array($tag, self::OMIT, true)
                    || (bool)preg_match('/\b(?:data-kgpt-ignore|contenteditable|hidden)\b/i', $part)
                    || (bool)preg_match('/\baria-hidden\s*=\s*["\']?true\b/i', $part)
                    || (bool)preg_match('/\bclass\s*=\s*["\'][^"\']*\bkgpt-/i', $part);
                if ($shell && !$omit) {
                    foreach (['placeholder','aria-label','title'] as $attr) {
                        if (preg_match('~\b' . $attr . '\s*=\s*(["\'])(.*?)\1~is', $part, $hit)) {
                            self::add($hit[2], $seen, $total);
                        }
                    }
                }
                if (!in_array($tag, self::VOID, true) && !preg_match('~/\s*>$~', $part)) {
                    $stack[] = ['name'=>$tag,'shell'=>$shell,'omit'=>$omit];
                }
                continue;
            }
            $parent = $stack ? $stack[count($stack)-1] : ['shell'=>false,'omit'=>false];
            if ($parent['shell'] && !$parent['omit']) self::add($part, $seen, $total);
        }
        return array_keys($seen);
    }
    private static function add(string $raw, array &$seen, int &$total): void {
        $s = self::normalize($raw);
        if (!self::translate_candidate($s) || isset($seen[$s])) return;
        if (count($seen) >= 240 || $total + strlen($s) > 24000) return;
        $seen[$s] = true;
        $total += strlen($s);
    }
    public static function chunks(array $segments): array {
        $chunks=[]; $current=[]; $bytes=0;
        foreach ($segments as $s) {
            if ($current && (count($current) >= 25 || $bytes + strlen($s) > 4500)) {
                $chunks[] = $current; $current=[]; $bytes=0;
            }
            $current[] = $s; $bytes += strlen($s);
        }
        if ($current) $chunks[] = $current;
        return $chunks;
    }
    public static function inject_manifest(string $html): string {
        if (!str_contains(strtolower($html), '</body>')) return $html;
        $texts = KGPT_Gemini::api_key() !== '' ? self::extract($html) : [];
        // A typed search term is a visitor input, not an official published source.
        // Never include any reflected search term in a model prompt.
        if ($texts && isset($_GET['q']) && is_scalar($_GET['q'])) {
            $query = self::normalize((string)$_GET['q']);
            if (strlen($query) >= 3) $texts = array_values(array_filter($texts, static fn($s) => stripos($s, $query) === false));
        }
        if ($texts) $texts[] = 'Machine translation. Check important dates, fees and requirements against the linked official sources.';
        $digest = hash('sha256', wp_json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($texts && KGPT_Gemini::api_key() !== '') {
            set_transient('kgpt_m_' . $digest, ['chunks'=>self::chunks($texts),'created'=>time()], self::MANIFEST_TTL);
        }
        $config = [
            'version'=>KGPT_VERSION, 'source'=>self::base_language(), 'enabled'=>KGPT_Gemini::api_key() !== '',
            'manifest'=>$texts ? $digest : '', 'count'=>count($texts),
            'endpoint'=>rest_url('kgpt/v1/translate'), 'languages'=>self::LOCALES,
        ];
        $script = '<script type="application/json" id="kgpt-config" data-kgpt-ignore>' .
            wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        return (string) preg_replace('~</body>~i', $script . "\n</body>", $html, 1);
    }
    /** Public endpoint cannot accept arbitrary text: manifest ID refers only to server-rendered public HTML. */
    public static function rest_translate($request) {
        $lang = self::locale((string) $request->get_param('language'));
        $manifest = (string) $request->get_param('manifest');
        $part = $request->get_param('chunk');
        if (!$lang || in_array($lang, ['ko','en'],true) || !preg_match('/^[a-f0-9]{64}$/D',$manifest)
            || !is_numeric($part) || (string)(int)$part !== (string)$part || (int)$part < 0 || (int)$part > 15) {
            return new WP_Error('kgpt_bad_request', 'Unsupported translation request.', ['status'=>422]);
        }
        if (KGPT_Gemini::api_key() === '') return new WP_Error('kgpt_disabled','Translation is not configured.',['status'=>503]);
        $stored = get_transient('kgpt_m_' . $manifest);
        if (!is_array($stored) || !isset($stored['chunks'][(int)$part])) {
            return new WP_Error('kgpt_expired','Public page translation has expired. Reload the page.',['status'=>410]);
        }
        $texts = $stored['chunks'][(int)$part];
        if (!is_array($texts) || !$texts || count($texts)>25) return new WP_Error('kgpt_bad_manifest','Invalid manifest.',['status'=>422]);
        $cache = 'kgpt_t_' . hash('sha256', KGPT_Gemini::model().'|'.$lang.'|'.wp_json_encode($texts,JSON_UNESCAPED_UNICODE));
        $translated = get_transient($cache);
        if (!is_array($translated)) {
            if (!self::quota()) return new WP_Error('kgpt_limited', 'Translation usage limit reached. Try again later.', ['status'=>429]);
            $translated = KGPT_Gemini::translate($texts, $lang);
            if (!is_array($translated)) return new WP_Error('kgpt_provider', 'Machine translation temporarily unavailable.', ['status'=>503]);
            set_transient($cache, $translated, self::RESULT_TTL);
        }
        $mapping=[];
        foreach ($texts as $i=>$source) $mapping[$source] = $translated[$i] ?? $source;
        $response = rest_ensure_response(['translations'=>$mapping, 'chunk'=>(int)$part, 'total'=>count($stored['chunks']), 'machine'=>true]);
        $response->header('Cache-Control','no-store');
        return $response;
    }
    private static function quota(): bool {
        // Quota is for uncached provider calls; no raw IP, user agent, email or form data is stored.
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $bucket='kgpt_q_'.hash('sha256',$ip.'|'.gmdate('Y-m-d-H'));
        $count=(int)get_transient($bucket);
        $global='kgpt_g_'.gmdate('Y-m-d');
        $daily=(int)get_transient($global);
        if ($count >= 50 || $daily >= 600) return false;
        set_transient($bucket,$count+1,3600);
        set_transient($global,$daily+1,86400);
        return true;
    }
}

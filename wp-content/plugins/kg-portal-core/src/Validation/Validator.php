<?php

defined('ABSPATH') || exit;

/** Validates all 84 logical catalog fields, including typed repeat items. */
final class KGP_Validator {
    public static function fail(string $message): never {
        throw new KGP_Domain_Exception('validation_failed', $message);
    }

    public static function id(string $value, string $label = 'public_id'): string {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/D', $value)) {
            self::fail($label . ' must be 2-64 ASCII letters, digits, _ or -.');
        }
        return $value;
    }

    public static function plain($value, string $label, int $max = 2000): string {
        if (!is_string($value) || trim($value) === '' || (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > $max) {
            self::fail($label . ' must be nonempty text (max ' . $max . ' characters).');
        }
        // Values are deliberately plain text; rich HTML is not accepted as source evidence.
        if (preg_match('/<\/?[a-z][^>]*>/i', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            self::fail($label . ' contains markup or control characters.');
        }
        return trim($value);
    }

    public static function url($value, string $label): string {
        if (!is_string($value) || (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > 1000) {
            self::fail($label . ' must be a URL.');
        }
        $parsed = wp_parse_url($value);
        if (!is_array($parsed) || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true) || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass']) || !filter_var($value, FILTER_VALIDATE_URL)) {
            self::fail($label . ' must be a public http(s) URL.');
        }
        // Reject private/internal/localhost targets for published official links.
        $host = strtolower($parsed['host']);
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || preg_match('/(^|\.)local$/', $host)) {
            self::fail($label . ' must not point to localhost.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::fail($label . ' must reference a public domain rather than a numeric IP.');
        }
        return $value;
    }

    public static function decimal($value, string $label, bool $percentage = false): string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            self::fail($label . ' must be a decimal amount.');
        }
        $v = (string)$value;
        if (!preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,2})?$/D', $v)) {
            self::fail($label . ' must be a non-negative decimal with at most 2 fractional digits.');
        }
        if ($percentage && (float)$v > 100.0) {
            self::fail($label . ' must be at most 100.');
        }
        return $v;
    }

    public static function date(string $value, string $label): string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) || !checkdate((int)($m[2] ?? 0), (int)($m[3] ?? 0), (int)($m[1] ?? 0))) {
            self::fail($label . ' must be a valid YYYY-MM-DD date.');
        }
        return $value;
    }

    public static function date_with_precision($value): array {
        if (!is_array($value) || array_is_list($value)) {
            self::fail('Date must be an object with date and precision.');
        }
        $precision = $value['precision'] ?? 'day';
        $date = $value['date'] ?? null;
        if (!in_array($precision, ['year', 'month', 'day', 'minute', 'second'], true) || !is_string($date)) {
            self::fail('Invalid date precision or date.');
        }
        if ($precision === 'year') {
            if (!preg_match('/^\d{4}$/D', $date)) self::fail('Year precision requires YYYY.');
        } elseif ($precision === 'month') {
            if (!preg_match('/^(\d{4})-(\d{2})$/D', $date, $m) || (int)($m[2] ?? 0) < 1 || (int)($m[2] ?? 0) > 12) self::fail('Month precision requires YYYY-MM.');
        } elseif ($precision === 'day') {
            self::date($date, 'Date');
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/D', $date)) self::fail('Timed date requires YYYY-MM-DDTHH:MM[:SS].');
            [$d, $time] = explode('T', $date, 2);
            self::date($d, 'Date');
            $parts = explode(':', $time);
            if ((int)$parts[0] > 23 || (int)$parts[1] > 59 || (isset($parts[2]) && (int)$parts[2] > 59)) self::fail('Invalid clock time.');
            if ($precision === 'second' && count($parts) !== 3) self::fail('Second precision requires seconds.');
        }
        if (isset($value['timezone'])) {
            if (!is_string($value['timezone']) || !in_array($value['timezone'], timezone_identifiers_list(), true)) {
                self::fail('Invalid IANA timezone.');
            }
        } elseif (in_array($precision, ['minute', 'second'], true)) {
            self::fail('Timed dates require an IANA timezone.');
        }
        return $value;
    }

    private static function assoc($value, string $label): array {
        if (!is_array($value) || array_is_list($value)) {
            self::fail($label . ' must be an object.');
        }
        return $value;
    }

    private static function required_text(array $value, string $key): void {
        if (!array_key_exists($key, $value)) {
            self::fail('Required field missing: ' . $key);
        }
        self::plain($value[$key], $key);
    }

    private static function sanitize_tree($value, int $depth = 0) {
        if ($depth > 8) self::fail('Structured value exceeds maximum nesting depth.');
        if (is_array($value)) {
            if (count($value) > 200) self::fail('Structured value has too many elements.');
            $result = [];
            foreach ($value as $key => $part) {
                if (is_string($key) && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $key)) self::fail('Invalid object key.');
                $result[$key] = self::sanitize_tree($part, $depth + 1);
            }
            return $result;
        }
        if (is_string($value)) {
            if ((function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > 15000) self::fail('Structured string is too long.');
            if (preg_match('/<\/?[a-z][^>]*>/i', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) self::fail('Structured text contains HTML or control characters.');
            return trim($value);
        }
        if (is_float($value) && !is_finite($value)) self::fail('Non-finite number.');
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
        self::fail('Unsupported structured value type.');
    }

    private static function money($value): array {
        $v = self::assoc($value, 'Money item');
        if (($v['currency'] ?? '') !== 'KRW') self::fail('Only original KRW values are permitted.');
        if (!in_array($v['unit'] ?? '', ['semester', 'month', 'year', 'one_time', 'program_total', 'term', 'other'], true)) {
            self::fail('Money item needs an official charge unit.');
        }
        $has_amount = array_key_exists('amount', $v);
        $has_range = array_key_exists('min_amount', $v) && array_key_exists('max_amount', $v);
        if ($has_amount === $has_range) self::fail('Money item must contain either amount or min_amount/max_amount.');
        if ($has_amount) {
            $v['amount'] = self::decimal($v['amount'], 'amount');
        } else {
            $v['min_amount'] = self::decimal($v['min_amount'], 'min_amount');
            $v['max_amount'] = self::decimal($v['max_amount'], 'max_amount');
            if ((float)$v['min_amount'] > (float)$v['max_amount']) self::fail('Money range min must be <= max.');
        }
        if (isset($v['label'])) self::plain($v['label'], 'label');
        if (isset($v['effective_year'])) self::check('year', $v['effective_year']);
        if (isset($v['applicability'])) self::plain($v['applicability'], 'applicability');
        return self::sanitize_tree($v);
    }

    private static function structured(string $kind, $value): array {
        $v = self::assoc($value, $kind);
        $required = [
            'document' => ['name'], 'document_authentication' => ['method'],
            'language_requirement' => ['test'], 'waiver' => ['condition'],
            'official_major' => ['name'], 'scholarship' => ['name', 'benefit'],
            'official_evaluation' => ['organization', 'year'],
            'certification' => ['name', 'course_type'],
            'restriction' => ['name', 'course_type'],
            'official_address' => ['address'], 'official_guidance' => ['title'],
            'official_contact' => ['department'], 'official_program' => ['name'],
            'official_case' => ['title'], 'published_statistic' => ['reference_date'],
        ];
        foreach ($required[$kind] ?? [] as $key) {
            if ($key === 'year') {
                self::check('year', $v[$key] ?? null);
            } elseif ($key === 'reference_date') {
                self::date_with_precision($v[$key] ?? null);
            } else {
                self::required_text($v, $key);
            }
        }
        if ($kind === 'published_statistic') {
            if (!array_key_exists('count', $v) && !array_key_exists('percentage', $v)) self::fail('Statistic needs count or percentage.');
            if (isset($v['count']) && (!is_int($v['count']) || $v['count'] < 0)) self::fail('Statistic count must be nonnegative integer.');
            if (isset($v['percentage'])) $v['percentage'] = self::decimal($v['percentage'], 'percentage', true);
        }
        if ($kind === 'structured_schedule') {
            if (!array_key_exists('start', $v) && !array_key_exists('end', $v)) self::fail('Schedule needs start or end.');
            if (isset($v['start'])) self::date_with_precision($v['start']);
            if (isset($v['end'])) self::date_with_precision($v['end']);
            if (isset($v['start'], $v['end']) && $v['start']['precision'] === $v['end']['precision'] && $v['start']['date'] > $v['end']['date']) {
                self::fail('Schedule start exceeds end.');
            }
        }
        if ($kind === 'scholarship') {
            if (isset($v['period']) && !in_array($v['period'], ['first_semester', 'later_semesters', 'both', 'other'], true)) self::fail('Invalid scholarship period.');
        }
        if (isset($v['url'])) self::url($v['url'], $kind . '.url');
        if (isset($v['official_url'])) self::url($v['official_url'], $kind . '.official_url');
        if (isset($v['amount'])) self::decimal($v['amount'], $kind . '.amount');
        if (isset($v['valid_from'])) self::date($v['valid_from'], 'valid_from');
        if (isset($v['valid_to'])) self::date($v['valid_to'], 'valid_to');
        return self::sanitize_tree($v);
    }

    public static function check(string $type, $value) {
        if (str_starts_with($type, 'repeat:')) {
            // One repeat item per field_entry. This permits independent evidence for each item.
            return self::check(substr($type, 7), $value);
        }
        if (str_starts_with($type, 'enum:')) {
            $allowed = explode('|', substr($type, 5));
            if (!is_string($value) || !in_array($value, $allowed, true)) self::fail('Invalid enum: ' . implode(', ', $allowed));
            return $value;
        }
        return match ($type) {
            'localized_text' => self::plain($value, 'Localized text', 15000),
            'url' => self::url($value, 'Official URL'),
            'year' => (function () use ($value) {
                if (!is_int($value) || $value < 1900 || $value > 2200) self::fail('Year must be a four-digit integer.');
                return $value;
            })(),
            'nullable_boolean' => (function () use ($value) {
                if (!is_null($value) && !is_bool($value)) self::fail('Expected true, false or null.');
                return $value;
            })(),
            'money_item' => self::money($value),
            'date_with_precision' => self::date_with_precision($value),
            'duration' => (function () use ($value) {
                $v = self::assoc($value, 'Duration');
                if (!is_int($v['value'] ?? null) || $v['value'] <= 0 || !in_array($v['unit'] ?? null, ['day', 'week', 'month', 'year', 'hour'], true)) self::fail('Invalid duration value/unit.');
                return self::sanitize_tree($v);
            })(),
            'locale_code' => (function () use ($value) {
                if (!is_string($value) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $value)) self::fail('Invalid BCP47 language tag.');
                return $value;
            })(),
            'structured_schedule', 'document', 'document_authentication', 'language_requirement', 'waiver',
            'official_major', 'scholarship', 'official_evaluation', 'certification', 'restriction',
            'official_address', 'official_guidance', 'official_contact', 'official_program',
            'official_case', 'published_statistic' => self::structured($type, $value),
            default => throw new KGP_Domain_Exception('unknown_type', 'No validator registered for ' . $type),
        };
    }

    public static function field(string $section, string $key, $value, string $item_key = ''): array {
        $sections = KGP_Field_Catalog::sections();
        if (!isset($sections[$section]['fields'][$key])) self::fail('Unknown field: ' . $section . '/' . $key);
        $type = $sections[$section]['fields'][$key];
        if (str_starts_with($type, 'repeat:') && $item_key === '') self::fail('Repeated field needs stable item_key.');
        if (!str_starts_with($type, 'repeat:') && $item_key !== '') self::fail('Non-repeat field may not use item_key.');
        if ($item_key !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $item_key)) self::fail('Invalid item_key.');
        return ['type' => $type, 'value' => self::check($type, $value)];
    }

    public static function assert_catalog_covered(): array {
        $count = 0;
        foreach (KGP_Field_Catalog::sections() as $sec) {
            foreach ($sec['fields'] as $type) {
                $base = str_starts_with($type, 'repeat:') ? substr($type, 7) : $type;
                if (!str_starts_with($base, 'enum:') && !in_array($base, [
                    'localized_text', 'url', 'year', 'nullable_boolean', 'money_item', 'date_with_precision',
                    'duration', 'locale_code', 'structured_schedule', 'document', 'document_authentication',
                    'language_requirement', 'waiver', 'official_major', 'scholarship', 'official_evaluation',
                    'certification', 'restriction', 'official_address', 'official_guidance', 'official_contact',
                    'official_program', 'official_case', 'published_statistic',
                ], true)) self::fail('Uncovered validation type: ' . $type);
                ++$count;
            }
        }
        return ['sections' => count(KGP_Field_Catalog::sections()), 'fields' => $count];
    }
}

<?php
/** W2-1 / A03 -> P02 -> P03 search/detail integration checks. */
defined('ABSPATH') || exit;

final class KGP_Bridge_Command {
    /**
     * Read-only check of existing published catalog and detail parity.
     * This DOES NOT certify official sources against original documents.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum approved offerings to check (1-60, default 12).
     *
     * ## EXAMPLES
     *
     *     wp --user=1 kgp bridge check --limit=12
     *
     * @subcommand check
     */
    public function check(array $args, array $assoc): void {
        self::require_admin();
        $limit = filter_var($assoc['limit'] ?? 12, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>60]]);
        if ($limit === false) WP_CLI::error('Provide --limit between 1 and 60.');
        $catalog = KGP_Field_Catalog::sections();
        $coverage = KGP_Public_Bridge_Audit::catalog_coverage($catalog);
        if ($coverage !== ['sections'=>14,'fields'=>84]) WP_CLI::error('DET catalog differs from 14 sections / 84 fields.');
        $total = 0; $checked = 0; $issues = [];
        $page = 1;
        do {
            $result = KGP_Public_Catalog::search(['locale'=>'ko','page'=>$page]);
            $total = $result['total'];
            foreach ($result['items'] as $card) {
                if ($checked >= $limit) break;
                $id = (string)($card['offering_id'] ?? '');
                $detail = KGP_Public_Catalog::offering($id, 'ko');
                $en = KGP_Public_Catalog::offering($id, 'en');
                if (!$detail || !$en) {
                    $issues[] = 'Missing published detail: ' . $id;
                    continue;
                }
                foreach (KGP_Public_Bridge_Audit::inspect($card, $detail, $catalog) as $issue) $issues[] = $id . ': ' . $issue;
                foreach (KGP_Public_Bridge_Audit::inspect_languages($detail, $en) as $issue) $issues[] = $id . ': ' . $issue;
                $university = KGP_Public_Catalog::university((string)$card['university_id'], 'ko');
                if (!$university || !in_array($id, array_column($university['offerings']??[], 'offering_id'), true)) {
                    $issues[] = $id . ': absent from approved university offering list.';
                }
                $checked++;
            }
            $page++;
        } while ($checked < $limit && $page <= (int)$result['total_pages']);
        WP_CLI::log('Catalog contract: 14 DET sections / 84 fields.');
        WP_CLI::log('Published Offering count: ' . $total . ', inspected: ' . $checked . '.');
        foreach ($issues as $issue) WP_CLI::warning('MISMATCH: ' . $issue);
        if ($issues) WP_CLI::error('Read-only bridge check failed (' . count($issues) . ' issues). No rows changed.');
        if ($total === 0) {
            WP_CLI::warning('SKIP: No published real offerings; public parity cannot be verified against live data.');
            WP_CLI::success('Schema/catalog checks passed; real-data integration STILL PENDING.');
            return;
        }
        WP_CLI::success('Read-only P02/P03 parity passed for ' . $checked . '/' . $total . ' approved offering(s). Source authenticity and other offerings not audited.');
    }

    /**
     * Test 8 search criteria against three transaction-only offerings and 14-section
     * contract, including same-university cross-program isolation and KO/EN parity.
     * Synthetic data are always rolled back; local DDEV administrator only.
     *
     * ## EXAMPLES
     *
     *     wp --user=1 kgp bridge smoke
     *
     * @subcommand smoke
     */
    public function smoke(): void {
        self::require_admin();
        $host = strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));
        if (!str_ends_with($host, '.ddev.site')) WP_CLI::error('Bridge smoke is restricted to local DDEV (*.ddev.site).');
        if (!(KGP_Schema::status()['ok'] ?? false)) WP_CLI::error('KGP schema must be installed first.');
        $coverage = KGP_Public_Bridge_Audit::catalog_coverage(KGP_Field_Catalog::sections());
        if ($coverage !== ['sections'=>14,'fields'=>84]) WP_CLI::error('DET 14/84 catalog contract is incomplete.');
        $token = 'W21' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 14);
        $steps = [];
        try {
            KGP_DB::transaction(function () use ($token, &$steps) {
                $uniA = self::create_university($token . 'A', 'Test University ' . $token . ' A');
                $uniB = self::create_university($token . 'B', 'Test University ' . $token . ' B');
                // A1: Seoul / engineering / bachelor / EN / English-only / 4.1M / open
                // A2: Busan / arts / master / KO / not English-only / 6.2M / open
                // B1: Busan / science / bachelor / KO / not English-only / 5.3M / upcoming
                $a1 = self::create_offering($token . 'A1', $uniA, 'seoul', 'engineering', 'bachelor', '2020-01-01', '2040-12-31');
                $a2 = self::create_offering($token . 'A2', $uniA, 'busan', 'arts', 'master', '2020-01-01', '2040-12-31');
                $b1 = self::create_offering($token . 'B1', $uniB, 'busan', 'science', 'bachelor', '2050-01-01', '2051-12-31');
                foreach ([[$uniA, $a1], [$uniA, $a2], [$uniB, $b1]] as [$university, $offering]) {
                    // The university, campus, program, intake and offering share a verified issuer.
                    foreach ($offering['relations'] as $type => $id) {
                        if ($type === 'university') continue;
                        self::approve_identity($type, $id, $university['source']);
                    }
                }
                self::publish_field($a1['offering'], 'DET-02', 'teaching_languages', 'en', $uniA['source'], 'lang-en');
                self::publish_field($a1['offering'], 'DET-02', 'english_only_program', true, $uniA['source']);
                self::publish_field($a1['offering'], 'DET-03', 'tuition_items', ['currency'=>'KRW','unit'=>'semester','amount'=>'4100000'], $uniA['source'], 'fee-01');
                self::publish_field($a2['offering'], 'DET-02', 'teaching_languages', 'ko', $uniA['source'], 'lang-ko');
                self::publish_field($a2['offering'], 'DET-02', 'english_only_program', false, $uniA['source']);
                self::publish_field($a2['offering'], 'DET-03', 'tuition_items', ['currency'=>'KRW','unit'=>'semester','amount'=>'6200000'], $uniA['source'], 'fee-01');
                self::publish_field($b1['offering'], 'DET-02', 'teaching_languages', 'ko', $uniB['source'], 'lang-ko');
                self::publish_field($b1['offering'], 'DET-02', 'english_only_program', false, $uniB['source']);
                self::publish_field($b1['offering'], 'DET-03', 'tuition_items', ['currency'=>'KRW','unit'=>'semester','amount'=>'5300000'], $uniB['source'], 'fee-01');
                // Accreditation applies to the university, not as an inferred property of B.
                self::publish_field($uniA['id'], 'DET-07', 'certification_records',
                    ['name'=>'Synthetic approval','course_type'=>'degree','status'=>'certified'], $uniA['source'], 'accr-01', 'university');
                $steps[] = 'Approved 2 universities / 3 differently scoped offerings / official-source-backed tuition and languages';

                $expected = [$a1['id'],$a2['id'],$b1['id']];
                self::expect($token, [], $expected);
                self::expect($token, ['region'=>'seoul,busan'], $expected); // OR within a filter
                self::expect($token, ['region'=>'seoul','field'=>'engineering','level'=>'bachelor'], [$a1['id']]);
                self::expect($token, ['region'=>'seoul','field'=>'arts'], []); // no cross-offering field mix even WITHIN one university
                self::expect($token, ['field'=>'arts,science'], [$a2['id'],$b1['id']]);
                self::expect($token, ['level'=>'master'], [$a2['id']]);
                self::expect($token, ['teaching_lang'=>'en'], [$a1['id']]);
                self::expect($token, ['english_only'=>'true'], [$a1['id']]);
                self::expect($token, ['english_only'=>'false'], [$a2['id'],$b1['id']]);
                self::expect($token, ['certified'=>'true'], [$a1['id'],$a2['id']]);
                self::expect($token, ['certified'=>'false'], []); // Unknown certification is not 'no'.
                self::expect($token, ['admission_status'=>'open'], [$a1['id'],$a2['id']]);
                self::expect($token, ['admission_status'=>'upcoming'], [$b1['id']]);
                self::expect($token, ['cost_unit'=>'semester','tuition_max'=>'5000000'], [$a1['id']]);
                self::expect($token, ['cost_unit'=>'semester','tuition_min'=>'6000000'], [$a2['id']]);
                self::expect($token, ['level'=>'bachelor','cost_unit'=>'semester','sort'=>'tuition'], [$a1['id'],$b1['id']]);
                self::expect($token, ['region'=>'seoul','field'=>'arts','english_only'=>'true'], []);
                $steps[] = 'All 8 filter dimensions, OR/AND, original-unit tuition, sorting and cross-program isolation pass';

                foreach ($expected as $id) {
                    $koCard = self::single($token, $id, 'ko');
                    $enCard = self::single($token, $id, 'en');
                    $ko = KGP_Public_Catalog::offering($id, 'ko');
                    $en = KGP_Public_Catalog::offering($id, 'en');
                    self::must(is_array($ko) && is_array($en), 'Offering detail missing in one locale: ' . $id);
                    $issues = array_merge(
                        KGP_Public_Bridge_Audit::inspect($koCard, $ko, KGP_Field_Catalog::sections()),
                        KGP_Public_Bridge_Audit::inspect($enCard, $en, KGP_Field_Catalog::sections()),
                        KGP_Public_Bridge_Audit::inspect_languages($ko, $en)
                    );
                    self::must($issues === [], 'P02/P03 KO/EN mismatch: ' . implode('; ', $issues));
                }
                self::must(in_array('DET-07',array_column(KGP_Public_Catalog::offering($a1['id'],'ko')['sections'],'id'),true),
                    'Approved certification section DET-07 not visible in A1 detail.');
                $steps[] = 'P02 cards and P03 sections match in KO/EN; all published fields retain official source links';
                throw new KGP_Bridge_Rollback('W21_SYNTHETIC_ROLLBACK');
            });
            WP_CLI::error('Bridge transaction failed to trigger rollback.');
        } catch (KGP_Bridge_Rollback $e) {
            // Deliberately rollback ALL fixture data, including source and university records.
        } catch (Throwable $e) {
            WP_CLI::error('W2-1 bridge smoke failed and rolled back: ' . $e->getMessage());
        }
        foreach ($steps as $step) WP_CLI::log('PASS: ' . $step);
        WP_CLI::success('W2-1 synthetic integration passed. All synthetic rows rolled back.');
    }

    private static function create_university(string $prefix, string $name): array {
        $issuer = KGP_Evidence_Repository::create_issuer([
            'public_id'=>$prefix.'-iss','official_name'=>'Synthetic QA issuer '.$prefix,'issuer_type'=>'university',
        ]);
        $source = KGP_Evidence_Repository::create_source([
            'public_id'=>$prefix.'-src','issuer_id'=>$issuer,'document_title'=>'Synthetic QA original '.$prefix,
            'public_url'=>'https://example.org/'.$prefix.'/guide.pdf','document_locator'=>'page 2',
        ]);
        KGP_Evidence_Repository::approve_issuer($issuer);
        KGP_Evidence_Repository::verify_source($source);
        $id = KGP_Entity_Repository::create('university',[
            'public_id'=>$prefix.'-un','official_name_ko'=>'가상대학교 '.$prefix,
            'official_name_en'=>$name,'official_website'=>'https://example.org',
        ]);
        self::approve_identity('university',$id,$source);
        return ['id'=>$id,'source'=>$source];
    }

    private static function create_offering(string $prefix, array $uni, string $region, string $field, string $level, string $start, string $end): array {
        $campus = KGP_Entity_Repository::create('campus',[
            'public_id'=>$prefix.'-ca','university_id'=>$uni['id'],
            'official_name_ko'=>'가상캠퍼스 '.$prefix,'region_code'=>$region,
        ]);
        $program = KGP_Entity_Repository::create('program',[
            'public_id'=>$prefix.'-pr','university_id'=>$uni['id'],'campus_id'=>$campus,
            'official_name_ko'=>'가상전공 '.$prefix,'official_name_en'=>'Synthetic Program '.$prefix,
            'field_code'=>$field,'level_code'=>$level,
        ]);
        $intake = KGP_Entity_Repository::create('intake',[
            'public_id'=>$prefix.'-in','university_id'=>$uni['id'],
            'academic_year'=>2027,'academic_term'=>'fall','admission_type'=>'new',
            'start_date'=>$start,'end_date'=>$end,
        ]);
        $offering = KGP_Entity_Repository::create('offering',[
            'public_id'=>$prefix.'-off','program_id'=>$program,'intake_id'=>$intake,
        ]);
        return ['id'=>$prefix.'-off','offering'=>$offering,
            'relations'=>['university'=>$uni['id'],'campus'=>$campus,'program'=>$program,'intake'=>$intake,'offering'=>$offering]];
    }

    private static function approve_identity(string $type, int $id, int $source): void {
        $submission = KGP_Identity_Repository::submit($type,$id,$source,0,'page 2');
        KGP_Identity_Repository::approve($type,$id,$submission['lock_version']);
    }

    private static function publish_field(int $entity, string $section, string $field, $value, int $source, string $itemKey='', string $type='offering'): void {
        $draft = KGP_Field_Repository::save_draft($type,$entity,$section,$field,$value,
            [['source_id'=>$source,'original_locator'=>'page 2']],0,$itemKey);
        KGP_Field_Repository::submit($draft['entry_id'],$draft['lock_version']);
        KGP_Field_Repository::publish($draft['entry_id']);
    }

    private static function single(string $token, string $id, string $locale): array {
        $result = KGP_Public_Catalog::search(['q'=>$token,'locale'=>$locale]);
        foreach ($result['items'] as $item) if ($item['offering_id'] === $id) return $item;
        throw new RuntimeException('Missing ' . $locale . ' search card for ' . $id);
    }

    private static function expect(string $token, array $filters, array $ids): void {
        $result = KGP_Public_Catalog::search(['q'=>$token,'locale'=>'ko'] + $filters);
        $actual = array_column($result['items'],'offering_id');
        if (isset($filters['sort']) && $filters['sort']==='tuition') {
            self::must($actual===$ids, 'Tuition sort expected order '.implode(',',$ids).' / actual '.implode(',',$actual));
            return;
        }
        sort($actual);sort($ids);
        self::must($actual===$ids && $result['total']===count($ids),
            'Search filter mismatch '.wp_json_encode($filters).' expected '.implode(',',$ids).' actual '.implode(',',$actual));
    }

    private static function must(bool $value, string $message): void {
        if (!$value) throw new RuntimeException($message);
    }

    private static function require_admin(): void {
        if (!current_user_can('manage_options')) WP_CLI::error('Administrator required (--user=<admin-id>).');
    }
}

final class KGP_Bridge_Rollback extends RuntimeException {}

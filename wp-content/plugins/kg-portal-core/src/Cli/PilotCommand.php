<?php
/** W1-2 / M1 two-university source/approval pilot. */

defined('ABSPATH') || exit;

final class KGP_Pilot_Command {
    /**
     * Check exactly two existing real universities for technical readiness. No writes.
     * This does not independently inspect the original university documents.
     *
     * ## OPTIONS
     *
     * --university-ids=<id-a,id-b>
     * : Two distinct existing university public IDs (not internal DB IDs).
     *
     * ## EXAMPLES
     *
     *     wp --user=1 kgp pilot readiness --university-ids=UNI-001,UNI-002
     *
     * @subcommand readiness
     */
    public function readiness(array $args, array $assoc_args): void {
        self::require_admin();
        $raw = (string)($assoc_args['university-ids'] ?? '');
        if (!$raw || count(explode(',', $raw)) !== 2) {
            WP_CLI::error('Use --university-ids=<first-public-id>,<second-public-id>.');
        }
        try {
            $report = KGP_Pilot_Readiness::check(explode(',', $raw));
        } catch (Throwable $e) {
            WP_CLI::error('Pilot readiness could not complete: ' . $e->getMessage());
            return;
        }
        foreach ($report['universities'] as $item) {
            WP_CLI::log($item['university_id'] . ': ' . $item['approved_offerings'] .
                ' approved offering(s), ' . $item['approved_detail_fields'] . ' approved DET value(s)');
        }
        foreach ($report['warnings'] as $warning) WP_CLI::warning('REVIEW: ' . $warning);
        foreach ($report['issues'] as $issue) WP_CLI::warning('BLOCKER: ' . $issue);
        if (!$report['technically_ready']) {
            WP_CLI::error('M1 two-university technical readiness NOT YET PASS. Nothing changed.');
        }
        WP_CLI::success('M1 technical readiness passes. Independent source/content review is still required. No DB changes.');
    }

    /**
     * Exercise two synthetic universities, distinct sources, isolation and revocation.
     * All fixture rows are rolled back; runs only on *.ddev.site with administrator.
     *
     * ## EXAMPLES
     *
     *     wp --user=1 kgp pilot smoke
     *
     * @subcommand smoke
     */
    public function smoke(): void {
        self::require_admin();
        $host = strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));
        if (!str_ends_with($host, '.ddev.site')) {
            WP_CLI::error('Synthetic pilot smoke is permitted only on *.ddev.site.');
        }
        if (!KGP_Schema::status()['ok']) WP_CLI::error('Core schema is incomplete.');
        $token = 'W12' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 14);
        $steps = [];
        try {
            KGP_DB::transaction(function () use ($token, &$steps) {
                $pilot = [];
                foreach (['A' => ['region'=>'seoul','field'=>'engineering','price'=>'4100000'],
                           'B' => ['region'=>'busan','field'=>'arts','price'=>'6200000']] as $label => $spec) {
                    $prefix = $token . $label;
                    $issuer = KGP_Evidence_Repository::create_issuer([
                        'public_id'=>$prefix.'-iss', 'official_name'=>'SYNTHETIC QA source '.$label,
                        'issuer_type'=>'university',
                    ]);
                    $source = KGP_Evidence_Repository::create_source([
                        'public_id'=>$prefix.'-src', 'issuer_id'=>$issuer,
                        'document_title'=>'Synthetic test document '.$label,
                        'public_url'=>'https://example.org/qa-'.$label.'.pdf',
                        'document_locator'=>'page 2',
                        'applicable_to'=>'Local transaction-only pilot '.$label,
                    ]);
                    $university = KGP_Entity_Repository::create('university', [
                        'public_id'=>$prefix.'-un', 'official_name_ko'=>'가상파일럿대학교 '.$token.' '.$label,
                        'official_name_en'=>'Pilot QA University '.$token.' '.$label,
                        'official_website'=>'https://example.org/',
                    ]);
                    $campus = KGP_Entity_Repository::create('campus', [
                        'public_id'=>$prefix.'-ca', 'university_id'=>$university,
                        'official_name_ko'=>'테스트 캠퍼스 '.$label, 'region_code'=>$spec['region'],
                    ]);
                    $program = KGP_Entity_Repository::create('program', [
                        'public_id'=>$prefix.'-pr', 'university_id'=>$university,
                        'campus_id'=>$campus, 'official_name_ko'=>'테스트 학과 '.$label,
                        'field_code'=>$spec['field'], 'level_code'=>'bachelor',
                    ]);
                    $intake = KGP_Entity_Repository::create('intake', [
                        'public_id'=>$prefix.'-in', 'university_id'=>$university,
                        'academic_year'=>2027, 'academic_term'=>'fall', 'admission_type'=>'new',
                    ]);
                    $offering = KGP_Entity_Repository::create('offering', [
                        'public_id'=>$prefix.'-off','program_id'=>$program,'intake_id'=>$intake,
                    ]);
                    $submissions = [];
                    foreach (['university'=>$university,'campus'=>$campus,'program'=>$program,'intake'=>$intake,'offering'=>$offering] as $type=>$id) {
                        $submissions[$type] = KGP_Identity_Repository::submit($type,$id,$source,0,'page 2');
                    }
                    $draft = KGP_Field_Repository::save_draft('offering', $offering, 'DET-03', 'tuition_items',
                        ['amount'=>$spec['price'], 'currency'=>'KRW','unit'=>'semester','label'=>'Synthetic test tuition'],
                        [['source_id'=>$source,'original_locator'=>'page 2']], 0, 'fee_01');
                    KGP_Field_Repository::submit($draft['entry_id'],$draft['lock_version']);
                    $pilot[$label] = compact('issuer','source','university','campus','program','intake','offering','submissions','draft','prefix');
                }
                $ids = [$pilot['A']['prefix'].'-un', $pilot['B']['prefix'].'-un'];
                $before = KGP_Public_Catalog::search(['q'=>$token, 'locale'=>'ko']);
                self::must(count($before['items']) === 0, 'Draft-only universities are hidden from search.');
                self::must(KGP_Public_Catalog::university($ids[0]) === null,'Draft identity hidden.');
                self::must_fail(static fn() => KGP_Identity_Repository::approve('university',
                    $pilot['A']['university'], $pilot['A']['submissions']['university']['lock_version']),
                    'Unverified source must block identity approval');
                self::must_fail(static fn() => KGP_Field_Repository::publish($pilot['A']['draft']['entry_id']),
                    'Unverified source must block field publication');
                $steps[] = 'Draft invisible; unverified evidence blocks identity and field publication';

                self::must_fail(static fn() => KGP_Entity_Repository::create('offering', [
                    'public_id'=>$token.'-cross', 'program_id'=>$pilot['A']['program'],
                    'intake_id'=>$pilot['B']['intake']
                ]), 'Cross-university Offering relationship must be rejected');
                foreach ($pilot as $label=>$data) {
                    KGP_Evidence_Repository::approve_issuer($data['issuer']);
                    KGP_Evidence_Repository::verify_source($data['source']);
                    foreach (['university'=>$data['university'],'campus'=>$data['campus'],
                        'program'=>$data['program'],'intake'=>$data['intake'],'offering'=>$data['offering']] as $type=>$id) {
                        KGP_Identity_Repository::approve($type,$id,$data['submissions'][$type]['lock_version']);
                    }
                    KGP_Field_Repository::publish($data['draft']['entry_id']);
                }
                $steps[] = 'Two independent issuers and sources approved; five identities plus tuition published each';

                $result = KGP_Public_Catalog::search(['q'=>$token,'locale'=>'ko']);
                self::must(count($result['items'])===2, 'Exactly two synthetic approved Offerings are searchable.');
                $seoul = KGP_Public_Catalog::search(['q'=>$token,'region'=>'seoul','field'=>'engineering']);
                $busan = KGP_Public_Catalog::search(['q'=>$token,'region'=>'busan','field'=>'arts']);
                self::must(count($seoul['items'])===1 && $seoul['items'][0]['offering_id']===$pilot['A']['prefix'].'-off',
                    'Seoul engineering filters isolate university A.');
                self::must(count($busan['items'])===1 && $busan['items'][0]['offering_id']===$pilot['B']['prefix'].'-off',
                    'Busan arts filters isolate university B.');
                $cross = KGP_Public_Catalog::search(['q'=>$token,'region'=>'seoul','field'=>'arts']);
                self::must(count($cross['items'])===0, 'Cross-university combinations are rejected.');
                $fees = KGP_Public_Catalog::search(['q'=>$token,'cost_unit'=>'semester','tuition_min'=>'6000000']);
                self::must(count($fees['items'])===1 && $fees['items'][0]['offering_id']===$pilot['B']['prefix'].'-off',
                    'Semester tuition filter selects only university B.');
                $readiness = KGP_Pilot_Readiness::check($ids);
                self::must($readiness['technically_ready'] === true, 'Pilot technical readiness is clean for both universities.');
                $steps[] = 'Public filters isolate both universities; KO/EN offering/evidence technical readiness passes';

                $prior_user=get_current_user_id();
                wp_set_current_user(0);
                try {
                    self::must(KGP_Public_Catalog::search(['q'=>$token])['total']===2,
                        'Anonymous visitors can read both approved offerings.');
                    self::must_fail(static fn()=>KGP_Entity_Repository::create('university',[
                        'public_id'=>$token.'-attack', 'official_name_ko'=>'공격',
                        'official_name_en'=>'Unauthorized university','official_website'=>'https://example.org/'
                    ]), 'Anonymous entity write is denied');
                } finally {
                    wp_set_current_user($prior_user);
                }
                $steps[] = 'Anonymous public reads allowed, writes denied';
                $removed=KGP_Evidence_Repository::invalidate_source($pilot['A']['source'],'Synthetic invalidation');
                self::must($removed===1,'Source invalidation removes its one published field.');
                self::must(KGP_Public_Catalog::university($ids[0])===null,
                    'Invalidated university A is not publicly readable.');
                self::must(KGP_Public_Catalog::university($ids[1])!==null,
                    'Unaffected university B stays readable.');
                $after=KGP_Pilot_Readiness::check($ids);
                self::must($after['technically_ready']===false && $after['universities'][1]['issues']===[],
                    'Readiness audit catches revoked evidence without marking university B invalid.');
                $steps[] = 'Invalidation hides only university A; other university remains published';
                throw new KGP_Pilot_Rollback('ROLLBACK_PILOT_TEST');
            });
            WP_CLI::error('Pilot smoke did not trigger its expected rollback.');
        } catch (KGP_Pilot_Rollback $e) {
            // Deliberate rollback of all synthetic data. No public fake university remains.
        } catch (Throwable $e) {
            WP_CLI::error('W1-2 pilot smoke failed and rolled back: '.$e->getMessage());
        }
        foreach ($steps as $step) WP_CLI::log('PASS: ' . $step);
        WP_CLI::success('W1-2 two-university synthetic pilot passed; all fixture rows rolled back.');
    }

    private static function require_admin(): void {
        if (!current_user_can('manage_options')) {
            WP_CLI::error('Use --user=<administrator-id> for pilot tests.');
        }
    }

    private static function must(bool $predicate, string $message): void {
        if (!$predicate) throw new RuntimeException($message);
    }

    private static function must_fail(callable $fn, string $message): void {
        try { $fn(); } catch (KGP_Domain_Exception $e) { return; }
        throw new RuntimeException($message);
    }
}

final class KGP_Pilot_Rollback extends RuntimeException {}

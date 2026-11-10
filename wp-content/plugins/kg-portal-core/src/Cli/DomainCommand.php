<?php

defined('ABSPATH') || exit;

/** WordPress CLI checks. Smoke data are always rolled back. */
final class KGP_Domain_Command {
    public function setup(): void {
        KGP_Access::install_roles();
        WP_CLI::success('KGP roles and capabilities installed or refreshed (no data deleted).');
    }

    public function check(): void {
        $status = KGP_Schema::status();
        if (!$status['ok']) WP_CLI::error('Schema incomplete. Run wp kgp schema install.');
        $covered = KGP_Validator::assert_catalog_covered();
        WP_CLI::log('Tables: ' . count($status['existing']) . '/' . count(KGP_Schema::names()));
        WP_CLI::log('Catalog: ' . $covered['sections'] . ' sections; ' . $covered['fields'] . ' fields; validators complete.');
        foreach (['kgp_information_editor','kgp_reviewer','kgp_inquiry_manager','kgp_portal_manager'] as $role) {
            if (!get_role($role)) WP_CLI::error('Missing role: ' . $role . ' (run wp kgp domain setup).');
        }
        WP_CLI::success('KGP repository prerequisites passed.');
    }

    public function smoke(): void {
        $host = strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));
        if (!str_ends_with($host, '.ddev.site')) WP_CLI::error('Smoke test is only allowed on a *.ddev.site local site.');
        if (!current_user_can('manage_options')) WP_CLI::error('Use an admin context: ddev wp --user=localadmin kgp domain smoke');
        $status = KGP_Schema::status();
        if (!$status['ok']) WP_CLI::error('Schema incomplete.');
        $marker = 'T' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
        $steps = [];
        try {
            KGP_DB::transaction(function () use ($marker, &$steps) {
                $u = KGP_Entity_Repository::create('university', [
                    'public_id' => $marker . '-uni', 'official_name_ko' => '테스트대학교',
                    'official_name_en' => 'TEST University (not real data)', 'official_website' => 'https://example.org/',
                ]);
                $c = KGP_Entity_Repository::create('campus', [
                    'public_id' => $marker . '-cam', 'university_id' => $u, 'official_name_ko' => '테스트캠퍼스',
                ]);
                $p = KGP_Entity_Repository::create('program', [
                    'public_id' => $marker . '-pro', 'university_id' => $u, 'campus_id' => $c,
                    'official_name_ko' => '컴퓨터공학', 'field_code' => 'engineering', 'level_code' => 'bachelor',
                ]);
                $i = KGP_Entity_Repository::create('intake', [
                    'public_id' => $marker . '-int', 'university_id' => $u,
                    'academic_year' => 2027, 'academic_term' => 'fall', 'admission_type' => 'new',
                ]);
                $o = KGP_Entity_Repository::create('offering', [
                    'public_id' => $marker . '-off', 'program_id' => $p, 'intake_id' => $i,
                ]);
                $steps[] = 'Five related entities created';
                $another = KGP_Entity_Repository::create('university', [
                    'public_id' => $marker . '-uni2', 'official_name_ko' => '타대학',
                    'official_name_en' => 'Another example university', 'official_website' => 'https://example.org/',
                ]);
                $other_intake = KGP_Entity_Repository::create('intake', [
                    'public_id' => $marker . '-otherint', 'university_id' => $another,
                    'academic_year' => 2027, 'academic_term' => 'fall', 'admission_type' => 'new',
                ]);
                self::must_fail(function () use ($marker,$p,$other_intake) {
                    KGP_Entity_Repository::create('offering', [
                        'public_id' => $marker . '-invalid', 'program_id' => $p, 'intake_id' => $other_intake,
                    ]);
                }, 'invalid offering relationship');
                self::must_fail(function () {
                    KGP_Validator::field('DET-03','tuition_items',['amount'=>'-100', 'currency'=>'KRW', 'unit'=>'semester'], 'tuition_01');
                }, 'negative amount');
                $steps[] = 'Foreign-key relationship and negative fee blocked';
                $iss = KGP_Evidence_Repository::create_issuer([
                    'public_id' => $marker . '-issuer', 'official_name' => 'Test Source Agency', 'issuer_type' => 'university',
                ]);
                $src = KGP_Evidence_Repository::create_source([
                    'public_id' => $marker . '-src', 'issuer_id' => $iss,
                    'document_title' => 'Synthetic local test document', 'public_url' => 'https://example.org/prospectus.pdf',
                    'document_locator' => 'page 2',
                ]);
                $draft = KGP_Field_Repository::save_draft('offering', $o, 'DET-03', 'tuition_items',
                    ['amount'=>'4200000','currency'=>'KRW','unit'=>'semester','label'=>'Tuition'],
                    [['source_id'=>$src,'original_locator'=>'page 2']], 0, 'tuition_01');
                if (KGP_Public_Reader::field('offering',$o,'DET-03','tuition_items','tuition_01') !== null) throw new RuntimeException('Draft leaked publicly');
                KGP_Field_Repository::submit($draft['entry_id'], $draft['lock_version']);
                self::must_fail(fn() => KGP_Field_Repository::publish($draft['entry_id']), 'publish unverified source');
                KGP_Evidence_Repository::approve_issuer($iss);
                KGP_Evidence_Repository::verify_source($src);
                KGP_Field_Repository::publish($draft['entry_id']);
                $published = KGP_Public_Reader::field('offering', $o, 'DET-03', 'tuition_items', 'tuition_01');
                if (!$published || $published['value']['amount'] !== '4200000' || count($published['source_documents']) !== 1) throw new RuntimeException('Approved value not readable');
                $steps[] = 'Draft hidden / unverified publication blocked / verified publication visible';
                $entry = KGP_DB::require_row('field_entries', $draft['entry_id']);
                self::must_fail(fn() => KGP_Field_Repository::save_draft('offering',$o,'DET-03','tuition_items',
                    ['amount'=>'4300000','currency'=>'KRW','unit'=>'semester'],[['source_id'=>$src]],0,'tuition_01'), 'stale edit');
                $revised = KGP_Field_Repository::save_draft('offering',$o,'DET-03','tuition_items',
                    ['amount'=>'4300000','currency'=>'KRW','unit'=>'semester'],[['source_id'=>$src]],(int)$entry['lock_version'],'tuition_01');
                if (KGP_Public_Reader::field('offering',$o,'DET-03','tuition_items','tuition_01')['value']['amount'] !== '4200000') throw new RuntimeException('Draft overwrote approved value');
                KGP_Field_Repository::submit($revised['entry_id'], $revised['lock_version']);
                KGP_Field_Repository::reject($revised['entry_id'], 'Test rejection');
                if (KGP_Public_Reader::field('offering',$o,'DET-03','tuition_items','tuition_01')['value']['amount'] !== '4200000') throw new RuntimeException('Rejected revision overwrote approved value');
                $steps[] = 'Optimistic lock and immutable published version pass';
                $revoked = KGP_Evidence_Repository::invalidate_source($src, 'Synthetic invalidation test');
                if ($revoked !== 1 || KGP_Public_Reader::field('offering',$o,'DET-03','tuition_items','tuition_01') !== null) throw new RuntimeException('Evidence invalidation did not hide value');
                $steps[] = 'Invalid source immediately removes published field';
                throw new KGP_Smoke_Transaction_Rollback('ROLLBACK_TEST_ONLY');
            });
        } catch (KGP_Smoke_Transaction_Rollback $e) {
            // Intentional rollback. No synthetic institution is left in the DB.
        } catch (Throwable $e) {
            WP_CLI::error('Smoke failed and rolled back: ' . $e->getMessage());
        }
        foreach ($steps as $step) WP_CLI::log('PASS: ' . $step);
        WP_CLI::success('Domain smoke passed; synthetic test data rolled back.');
    }

    private static function must_fail(callable $test, string $case): void {
        try {
            $test();
        } catch (KGP_Domain_Exception $e) {
            return;
        }
        throw new RuntimeException('Expected rejection was not raised: ' . $case);
    }
}

final class KGP_Smoke_Transaction_Rollback extends RuntimeException {}

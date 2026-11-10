<?php
/**
 * Logical field catalog corresponding to DET-01 through DET-14 in spec v1.3.
 * This is a schema/contract catalog, NOT CRUD, validation or publication code.
 * Every publishable item is stored as a field_entry/version with source links.
 */

defined('ABSPATH') || exit;

final class KGP_Field_Catalog {
    /** @return array<string,array{title:string,fields:array<string,string>}> */
    public static function sections(): array {
        return [
            'DET-01' => [
                'title' => '입학 조건',
                'fields' => [
                    'application_route' => 'enum:new|transfer',
                    'eligibility' => 'localized_text',
                    'academic_requirements' => 'localized_text',
                    'required_documents' => 'repeat:document',
                    'document_authentication' => 'repeat:document_authentication',
                    'application_fee' => 'money_item',
                    'admission_fee' => 'money_item',
                    'application_schedule' => 'structured_schedule',
                    'selection_method' => 'localized_text',
                    'official_application_url' => 'url',
                ],
            ],
            'DET-02' => [
                'title' => '한국어와 영어 조건',
                'fields' => [
                    'admission_korean_tests' => 'repeat:language_requirement',
                    'admission_english_tests' => 'repeat:language_requirement',
                    'language_waiver_rules' => 'repeat:waiver',
                    'teaching_languages' => 'repeat:locale_code',
                    'english_only_program' => 'nullable_boolean',
                    'graduation_korean_requirements' => 'repeat:language_requirement',
                    'graduation_english_requirements' => 'repeat:language_requirement',
                ],
            ],
            'DET-03' => [
                'title' => '등록금과 장학금',
                'fields' => [
                    'tuition_items' => 'repeat:money_item',
                    'additional_official_fees' => 'repeat:money_item',
                    'entry_scholarships' => 'repeat:scholarship',
                    'continuation_scholarships' => 'repeat:scholarship',
                    'scholarship_retention_requirements' => 'localized_text',
                    'applicable_academic_year' => 'year',
                    'gks_host_institution' => 'nullable_boolean',
                ],
            ],
            'DET-04' => [
                'title' => '전공과 교육 정보',
                'fields' => [
                    'recruiting_majors' => 'repeat:official_major',
                    'curriculum' => 'localized_text',
                    'practical_facilities' => 'localized_text',
                    'faculty_information' => 'localized_text',
                    'english_course_coverage' => 'localized_text',
                    'department_url' => 'url',
                ],
            ],
            'DET-05' => [
                'title' => '어학연수 과정',
                'fields' => [
                    'term_structure' => 'localized_text',
                    'term_duration' => 'duration',
                    'term_tuition' => 'repeat:money_item',
                    'admission_periods' => 'repeat:structured_schedule',
                    'degree_progression_pathway' => 'localized_text',
                    'language_center_url' => 'url',
                ],
            ],
            'DET-06' => [
                'title' => '대학 개요와 공인 평가',
                'fields' => [
                    'institution_type' => 'enum:public|private|other',
                    'institution_overview' => 'localized_text',
                    'accredited_evaluations' => 'repeat:official_evaluation',
                    'degree_recognition_procedure' => 'localized_text',
                    'degree_recognition_official_link' => 'url',
                ],
            ],
            'DET-07' => [
                'title' => '인증과 비자 제한',
                'fields' => [
                    'certification_records' => 'repeat:certification',
                    'degree_course_certification' => 'repeat:certification',
                    'language_course_certification' => 'repeat:certification',
                    'visa_review_restrictions' => 'repeat:restriction',
                    'official_announcement_year' => 'year',
                ],
            ],
            'DET-08' => [
                'title' => '위치와 생활비 안내',
                'fields' => [
                    'campus_location' => 'official_address',
                    'transportation_information' => 'localized_text',
                    'official_cost_of_living_guidance' => 'repeat:official_guidance',
                    'cost_of_living_inclusions' => 'repeat:localized_text',
                    'cost_of_living_reference_date' => 'date_with_precision',
                ],
            ],
            'DET-09' => [
                'title' => '기숙사',
                'fields' => [
                    'eligibility' => 'localized_text',
                    'foreign_freshman_eligibility' => 'nullable_boolean',
                    'selection_method' => 'localized_text',
                    'dormitory_costs' => 'repeat:money_item',
                    'meal_inclusion' => 'nullable_boolean',
                    'application_schedule' => 'repeat:structured_schedule',
                ],
            ],
            'DET-10' => [
                'title' => '공식 공개 부대비용',
                'fields' => [
                    'application_fees' => 'repeat:money_item',
                    'admission_fees' => 'repeat:money_item',
                    'insurance_fees' => 'repeat:money_item',
                    'visa_residence_fees' => 'repeat:money_item',
                    'other_published_fees' => 'repeat:money_item',
                ],
            ],
            'DET-11' => [
                'title' => '외국인 학생 지원',
                'fields' => [
                    'international_office' => 'official_contact',
                    'service_languages' => 'repeat:locale_code',
                    'korean_language_support' => 'localized_text',
                    'adaptation_programs' => 'repeat:official_program',
                    'official_contacts' => 'repeat:official_contact',
                    'consultation_hours' => 'localized_text',
                ],
            ],
            'DET-12' => [
                'title' => '외국인 재학생 현황',
                'fields' => [
                    'student_counts_by_nationality' => 'repeat:published_statistic',
                    'foreign_student_ratio' => 'published_statistic',
                    'student_counts_by_course_type' => 'repeat:published_statistic',
                    'disclosure_reference_date' => 'date_with_precision',
                    'disclosure_year' => 'year',
                ],
            ],
            'DET-13' => [
                'title' => '비자와 체류 지원',
                'fields' => [
                    'document_issuance_scope' => 'localized_text',
                    'university_guidance_scope' => 'localized_text',
                    'official_agency_links' => 'repeat:url',
                    'eligibility_scope' => 'localized_text',
                    'reference_date' => 'date_with_precision',
                ],
            ],
            'DET-14' => [
                'title' => '취업과 진로',
                'fields' => [
                    'career_support_programs' => 'repeat:official_program',
                    'internship_programs' => 'repeat:official_program',
                    'published_alumni_cases' => 'repeat:official_case',
                    'employment_resources' => 'repeat:official_guidance',
                    'target_population' => 'localized_text',
                    'publication_year' => 'year',
                ],
            ],
        ];
    }

    public static function has_field(string $section, string $key): bool {
        $all = self::sections();
        return isset($all[$section]['fields'][$key]);
    }
}

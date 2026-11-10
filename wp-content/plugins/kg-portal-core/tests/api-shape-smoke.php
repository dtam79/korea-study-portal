<?php
/** Host-side structural preflight; not a substitute for WordPress/MariaDB integration tests. */
$root=dirname(__DIR__);
$schema=file_get_contents($root.'/src/Database/Schema.php');
$routes=file_get_contents($root.'/src/Api/RestRoutes.php');
$identity=file_get_contents($root.'/src/Repository/IdentityRepository.php');
$public=file_get_contents($root.'/src/Service/PublicCatalog.php');
assert_options(ASSERT_ACTIVE,1);
$must=[
 '20 tables listed'=>'entity_publications',
 'Identity source verification'=>'valid_source',
 'Identity published immutable value'=>'published_json',
 'Public REST namespace'=>'kgp/v1',
 'Public search'=>'/catalog',
 'University details'=>'/universities/',
 'Offering details'=>'/offerings/',
 'Field reviewer transition'=>'/publish',
];
foreach($must as $label=>$needle) {
    $haystack=in_array($label,['20 tables listed'],true)?$schema:($label==='Identity source verification'||$label==='Identity published immutable value'?$identity:$routes);
    if(!str_contains($haystack,$needle)) {fwrite(STDERR,"FAIL $label\n");exit(1);}
}
foreach(['same','tuition_min','tuition_max','english_only','certified','admission_status','source_documents'] as $word) {
    if($word!=='same' && !str_contains($public,$word) && !str_contains(file_get_contents($root.'/src/Service/PublicReader.php'),$word)) {
        fwrite(STDERR,"FAIL missing $word\n");exit(1);
    }
}
echo "PASS: schema upgrade, identity source gate, public/admin route structure present.\n";

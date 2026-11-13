<?php
/** Run: ddev wp eval-file wp-content/plugins/kg-portal-design/tests/lookup-integration.php --user=<administrator login>
 * Read-only: never creates users or records. Run after enabling the plugin.
 */
if(!defined('ABSPATH'))exit("Run through WP CLI eval-file.\n");
if(!current_user_can('manage_options'))throw new RuntimeException('Run as an existing administrator with --user.');
$id=get_current_user_id();
try {
 foreach(['university','campus','program','intake','offering','source','issuer','user','article','entry'] as $type){
  $request=new WP_REST_Request('GET','/kgds/v1/lookup');$request->set_param('type',$type);$response=rest_do_request($request);
  if($response->get_status()!==200)throw new RuntimeException($type.': '.wp_json_encode($response->get_data()));
  $data=$response->get_data();if(!isset($data['items'])||!is_array($data['items']))throw new RuntimeException('Bad response: '.$type);
  echo "PASS $type search\n";
  if($data['items']){
   $first=$data['items'][0];$request->set_param('ids',(string)$first['id']);$single=rest_do_request($request)->get_data();
   if(count($single['items'])!==1||(int)$single['items'][0]['id']!==(int)$first['id'])throw new RuntimeException('Hydration failed: '.$type);
   echo "PASS $type selected identity lookup\n";
  }
 }
 wp_set_current_user(0);
 $request=new WP_REST_Request('GET','/kgds/v1/lookup');$request->set_param('type','university');$response=rest_do_request($request);
 if(!in_array($response->get_status(),[401,403],true))throw new RuntimeException('Anonymous read was not blocked.');
 echo "PASS anonymous read blocked\n";
} finally {wp_set_current_user($id);}
echo "Check assigned editor scoping with your existing editor account; this script does not create assignments.\n";

<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class POPP_Playbook_REST_Controller {
	private string $namespace = 'popp/v2';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/playbooks', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => array( $this, 'logged_in' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create' ), 'permission_callback' => array( $this, 'logged_in' ) ),
		) );
		register_rest_route( $this->namespace, '/playbooks/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => array( $this, 'owns' ) ),
			array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update' ), 'permission_callback' => array( $this, 'owns' ) ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'delete' ), 'permission_callback' => array( $this, 'owns' ) ),
		) );
		foreach ( array( 'duplicate', 'archive' ) as $action ) {
			register_rest_route( $this->namespace, "/playbooks/(?P<id>\d+)/{$action}", array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, $action ), 'permission_callback' => array( $this, 'owns' ) ) );
		}
	}

	public function logged_in(): bool { return is_user_logged_in(); }
	public function owns( WP_REST_Request $request ): bool { return (bool) $this->find( absint( $request['id'] ) ); }

	public function index(): WP_REST_Response {
		global $wpdb; $table = POPP_Database::playbook_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d ORDER BY (status='archived') ASC, updated_at DESC", get_current_user_id() ), ARRAY_A );
		return rest_ensure_response( array_map( array( $this, 'hydrate' ), $rows ?: array() ) );
	}

	public function create( WP_REST_Request $request ) {
		global $wpdb; $p = (array) $request->get_json_params(); $title = sanitize_text_field( $p['title'] ?? '' );
		if ( '' === $title ) return new WP_Error( 'popp_missing_title', __( 'Enter a name for the playbook.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( POPP_Database::playbook_table(), array( 'user_id'=>get_current_user_id(), 'title'=>$title, 'status'=>'draft', 'current_step'=>1, 'completion_percent'=>0, 'playbook_data'=>wp_json_encode( $this->defaults() ), 'revision'=>1, 'created_at'=>$now, 'updated_at'=>$now ) );
		if ( ! $ok ) return new WP_Error( 'popp_database_error', __( 'The playbook could not be created.', 'pop-progress-tracker' ), array( 'status' => 500 ) );
		return $this->show_by_id( (int) $wpdb->insert_id );
	}

	public function show( WP_REST_Request $request ) { return $this->show_by_id( absint( $request['id'] ) ); }

	public function update( WP_REST_Request $request ) {
		global $wpdb; $id = absint( $request['id'] ); $row = $this->find( $id ); $p = (array) $request->get_json_params();
		if ( ! $row ) return new WP_Error( 'popp_missing_playbook', __( 'Playbook not found.', 'pop-progress-tracker' ), array( 'status'=>404 ) );
		if ( ! isset( $p['revision'] ) || (int) $p['revision'] !== (int) $row['revision'] ) return new WP_Error( 'popp_write_conflict', __( 'This playbook changed in another tab. Reload it and try again.', 'pop-progress-tracker' ), array( 'status'=>409 ) );
		$raw = is_array( $p['data'] ?? null ) ? $p['data'] : array();
		if ( strlen( wp_json_encode( $raw ) ) > 500000 ) return new WP_Error( 'popp_too_large', __( 'This playbook contains too much data.', 'pop-progress-tracker' ), array( 'status'=>413 ) );
		$data = $this->clean( $raw ); $title = sanitize_text_field( $p['title'] ?? $row['title'] );
		$status = in_array( $p['status'] ?? '', array( 'draft','completed','archived' ), true ) ? $p['status'] : $row['status'];
		$step = min( 9, max( 1, absint( $p['currentStep'] ?? $row['current_step'] ) ) ); $completion = $this->completion( $data );
		$updated = $wpdb->update( POPP_Database::playbook_table(), array(
			'title'=>$title ?: $row['title'], 'segment_name'=>sanitize_text_field( $data['identity']['segment'] ?? '' ), 'company_name'=>sanitize_text_field( $data['identity']['company'] ?? '' ),
			'status'=>$status, 'current_step'=>$step, 'completion_percent'=>$completion, 'playbook_data'=>wp_json_encode( $data ), 'revision'=>(int)$row['revision']+1,
			'updated_at'=>current_time('mysql',true), 'completed_at'=>'completed'===$status ? current_time('mysql',true) : null,
		), array( 'id'=>$id, 'user_id'=>get_current_user_id(), 'revision'=>(int)$row['revision'] ) );
		if ( 1 !== $updated ) return new WP_Error( 'popp_write_conflict', __( 'The playbook could not be saved. Reload it and try again.', 'pop-progress-tracker' ), array( 'status'=>409 ) );
		return $this->show_by_id( $id );
	}

	public function duplicate( WP_REST_Request $request ) {
		global $wpdb; $row=$this->find(absint($request['id'])); $now=current_time('mysql',true);
		$ok=$wpdb->insert(POPP_Database::playbook_table(),array('user_id'=>get_current_user_id(),'title'=>$row['title'].' Copy','segment_name'=>$row['segment_name'],'company_name'=>$row['company_name'],'status'=>'draft','current_step'=>1,'completion_percent'=>$row['completion_percent'],'playbook_data'=>$row['playbook_data'],'revision'=>1,'created_at'=>$now,'updated_at'=>$now));
		return $ok ? $this->show_by_id((int)$wpdb->insert_id) : new WP_Error('popp_database_error',__('The playbook could not be duplicated.','pop-progress-tracker'),array('status'=>500));
	}
	public function archive( WP_REST_Request $request ) { global $wpdb; $ok=$wpdb->update(POPP_Database::playbook_table(),array('status'=>'archived','updated_at'=>current_time('mysql',true)),array('id'=>absint($request['id']),'user_id'=>get_current_user_id())); return false===$ok ? new WP_Error('popp_database_error',__('The playbook could not be archived.','pop-progress-tracker'),array('status'=>500)) : rest_ensure_response(array('archived'=>true)); }
	public function delete( WP_REST_Request $request ) { global $wpdb; $ok=$wpdb->delete(POPP_Database::playbook_table(),array('id'=>absint($request['id']),'user_id'=>get_current_user_id()),array('%d','%d')); return false===$ok ? new WP_Error('popp_database_error',__('The playbook could not be deleted.','pop-progress-tracker'),array('status'=>500)) : rest_ensure_response(array('deleted'=>true)); }

	private function find( int $id ): ?array { global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.POPP_Database::playbook_table().' WHERE id=%d AND user_id=%d',$id,get_current_user_id()),ARRAY_A); return $row ?: null; }
	private function show_by_id( int $id ) { $row=$this->find($id); return $row ? rest_ensure_response($this->hydrate($row)) : new WP_Error('popp_missing_playbook',__('Playbook not found.','pop-progress-tracker'),array('status'=>404)); }
	private function hydrate( array $r ): array { return array('id'=>(int)$r['id'],'title'=>$r['title'],'segmentName'=>$r['segment_name'],'companyName'=>$r['company_name'],'status'=>$r['status'],'currentStep'=>(int)$r['current_step'],'completionPercent'=>(int)$r['completion_percent'],'revision'=>(int)$r['revision'],'data'=>json_decode($r['playbook_data'],true) ?: $this->defaults(),'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']); }
	private function clean( array $input ): array { $out=array(); foreach(array_slice($input,0,100,true) as $k=>$v){$k=is_int($k)?$k:substr(sanitize_key((string)$k),0,80); if(is_array($v))$out[$k]=$this->clean($v); elseif(is_bool($v))$out[$k]=$v; else $out[$k]=sanitize_textarea_field((string)$v);} return $out; }
	private function completion( array $d ): int { $checks=array(!empty($d['identity']['name']),!empty($d['growth']['goal']),!empty($d['growth']['selected']),!empty($d['action']['activities'][0]['name']),!empty($d['action']['rocks'][0]['text']),!empty($d['identity']['segment']),!empty($d['icp']['address']),!empty($d['icp']['problems'][0]['text']),!empty($d['lead']['selected']),!empty($d['capabilities']['when']),!empty($d['discovery']['circumstantial'][0]),!empty($d['presentation']['opening']),!empty($d['bridges']['assessment_name'])); return (int)round(count(array_filter($checks))/count($checks)*100); }
	private function defaults(): array { return array(
		'identity'=>array('name'=>'','company'=>'','segment'=>''),
		'growth'=>array('history'=>array_fill(0,5,array('year'=>'','sales'=>'')),'goal_year'=>'','goal'=>'','confidence'=>'','selected'=>array(),'accounts'=>array()),
		'action'=>array('activities'=>array_fill(0,3,array('id'=>'','name'=>'','minimum'=>'')),'rocks'=>array_fill(0,3,array('id'=>'','text'=>''))),
		'icp'=>array('address'=>'','economic_buyer'=>'','user_title'=>'','technical_influencer'=>'','champion'=>'','problems'=>array_fill(0,3,array('id'=>'','text'=>''))),
		'lead'=>array('selected'=>array(),'plans'=>array(),'campaigns'=>array_fill(0,3,array('subject'=>'','script'=>'')),'breakup'=>array('subject'=>'','script'=>''),'objections'=>array_fill(0,3,array('acknowledge'=>'','pivot'=>'','reason'=>'','ask'=>'')),'pitch'=>array('profile'=>'','problems'=>'','differentiators'=>'','ask'=>'')),
		'capabilities'=>array('when'=>'','domain'=>'','trends'=>'','case_studies'=>'','services'=>'','process'=>'','differentiators'=>''),
		'discovery'=>array('circumstantial'=>array_fill(0,5,''),'problem'=>array_fill(0,5,''),'impact'=>array_fill(0,5,''),'results'=>array_fill(0,5,'')),
		'presentation'=>array('opening'=>'','value_proposition'=>'','proof'=>'','wiify'=>'','point_b'=>'','outline'=>'','objections'=>'','success'=>'','customer_value'=>'','process'=>'','ask'=>'','close'=>'','anchor'=>''),
		'bridges'=>array('assessment_name'=>'','objectives'=>'','success'=>'','value'=>'','intent'=>'','looking_at'=>'','expert_view'=>'','scoring'=>'','compliance'=>'','insider'=>'','bottom_line'=>'','roundtable_anchor'=>'','topics'=>'','date'=>'','location'=>'','invitees'=>array('')),
	); }
}

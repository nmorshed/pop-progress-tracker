<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POPP_REST_Controller {
	private string $namespace = 'popp/v2';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/action-plans',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => array( $this, 'logged_in' ) ),
				array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create' ), 'permission_callback' => array( $this, 'logged_in' ) ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/action-plans/(?P<id>\d+)',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => array( $this, 'can_view_plan' ) ),
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update' ), 'permission_callback' => array( $this, 'owns_plan' ) ),
				array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'archive' ), 'permission_callback' => array( $this, 'owns_plan' ) ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/team',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'team' ),
				'permission_callback' => array( $this, 'can_view_team' ),
				'args'                => array(
					'page'     => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
					'per_page' => array( 'sanitize_callback' => 'absint', 'default' => 20 ),
					'search'   => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				),
			)
		);
	}

	public function logged_in(): bool {
		return is_user_logged_in();
	}

	public function owns_plan( WP_REST_Request $request ): bool {
		return (bool) $this->find( absint( $request['id'] ) );
	}

	public function can_view_plan( WP_REST_Request $request ): bool {
		$owner = $this->owner_id( absint( $request['id'] ) );
		return $owner && $this->can_view_user( get_current_user_id(), $owner );
	}

	public function can_view_team(): bool {
		$viewer = get_current_user_id();
		return user_can( $viewer, 'manage_options' ) || ! empty( $this->visible_student_ids( $viewer ) );
	}

	public function index(): WP_REST_Response {
		global $wpdb;
		$table = POPP_Database::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status, revision, created_at, updated_at FROM {$table} WHERE user_id = %d AND status IN ('active', 'completed', 'archived') ORDER BY (status = 'active') DESC, (status = 'completed') DESC, updated_at DESC", get_current_user_id() ), ARRAY_A );
		return rest_ensure_response( array_map( array( $this, 'summary' ), $rows ?: array() ) );
	}

	public function team( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$viewer   = get_current_user_id();
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$search   = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$table    = POPP_Database::table();
		$users    = $wpdb->users;
		if ( user_can( $viewer, 'manage_options' ) ) {
			// Administrators see only users who have at least one action plan.
			$where = "p.status IN ('active', 'completed', 'archived') AND p.user_id != %d";
			$args  = array( $viewer );
			if ( '' !== $search ) {
				$where .= ' AND u.display_name LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$count_sql = "SELECT COUNT(DISTINCT p.user_id) FROM {$table} p INNER JOIN {$users} u ON u.ID = p.user_id WHERE {$where}";
			$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
			$page      = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
			$offset    = ( $page - 1 ) * $per_page;
			$list_sql  = "SELECT DISTINCT p.user_id AS id, u.display_name AS name FROM {$table} p INNER JOIN {$users} u ON u.ID = p.user_id WHERE {$where} ORDER BY u.display_name ASC LIMIT %d OFFSET %d";
			$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		} else {
			// Group leaders see every supplied student, with or without an action plan.
			$student_ids = $this->visible_student_ids( $viewer );
			if ( empty( $student_ids ) ) {
				return rest_ensure_response( $this->team_response( array(), 0, $page, $per_page ) );
			}
			$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
			$where        = "u.ID IN ({$placeholders})";
			$args         = $student_ids;
			if ( '' !== $search ) {
				$where .= ' AND u.display_name LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$count_sql = "SELECT COUNT(*) FROM {$users} u WHERE {$where}";
			$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
			$page      = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
			$offset    = ( $page - 1 ) * $per_page;
			$list_sql  = "SELECT u.ID AS id, u.display_name AS name FROM {$users} u WHERE {$where} ORDER BY u.display_name ASC LIMIT %d OFFSET %d";
			$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		}
		$ids       = array_map( 'absint', wp_list_pluck( $rows ?: array(), 'id' ) );
		$plans_by_student = array();
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$plans        = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, title, status, revision, created_at, updated_at FROM {$table} WHERE user_id IN ({$placeholders}) AND status IN ('active', 'completed', 'archived') ORDER BY (status = 'active') DESC, (status = 'completed') DESC, updated_at DESC", $ids ), ARRAY_A );
			foreach ( $plans ?: array() as $plan ) {
				$plans_by_student[ (int) $plan['user_id'] ][] = $this->summary( $plan );
			}
		}

		$members = array();
		foreach ( $rows ?: array() as $row ) {
			$members[] = array( 'id' => (int) $row['id'], 'name' => $row['name'], 'plans' => $plans_by_student[ (int) $row['id'] ] ?? array() );
		}
		return rest_ensure_response( $this->team_response( $members, $total, $page, $per_page ) );
	}

	private function team_response( array $members, int $total, int $page, int $per_page ): array {
		return array(
			'items'      => $members,
			'pagination' => array(
				'page'       => $page,
				'perPage'    => $per_page,
				'total'      => $total,
				'totalPages' => max( 1, (int) ceil( $total / $per_page ) ),
			),
		);
	}

	public function create( WP_REST_Request $request ) {
		global $wpdb;
		$params = (array) $request->get_json_params();
		$title  = sanitize_text_field( $params['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'popp_missing_title', __( 'Enter a name for the action plan.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		}
		$active_plan_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . POPP_Database::table() . ' WHERE user_id = %d AND status = %s ORDER BY updated_at DESC LIMIT 1', get_current_user_id(), 'active' ) );
		if ( $active_plan_id ) {
			return new WP_Error( 'popp_active_plan_exists', __( 'Archive your current action plan before creating a new one.', 'pop-progress-tracker' ), array( 'status' => 409, 'activePlanId' => $active_plan_id ) );
		}

		$data = $this->default_data();
		$now  = current_time( 'mysql', true );
		$ok   = $wpdb->insert(
			POPP_Database::table(),
			array(
				'user_id'    => get_current_user_id(),
				'title'      => $title,
				'status'     => 'active',
				'plan_data'  => wp_json_encode( $data ),
				'revision'   => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'popp_database_error', __( 'The action plan could not be created.', 'pop-progress-tracker' ), array( 'status' => 500 ) );
		}

		return $this->show_by_id( (int) $wpdb->insert_id );
	}

	public function show( WP_REST_Request $request ) {
		return $this->show_by_id( absint( $request['id'] ) );
	}

	public function update( WP_REST_Request $request ) {
		global $wpdb;
		$row    = $this->find( absint( $request['id'] ) );
		$params = (array) $request->get_json_params();
		if ( ! $row ) {
			return new WP_Error( 'popp_missing_plan', __( 'Action plan not found.', 'pop-progress-tracker' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== $row['status'] ) {
			return new WP_Error( 'popp_plan_locked', __( 'This completed or archived action plan is locked and cannot be edited.', 'pop-progress-tracker' ), array( 'status' => 409 ) );
		}
		if ( ! isset( $params['revision'] ) || (int) $params['revision'] !== (int) $row['revision'] ) {
			return new WP_Error( 'popp_write_conflict', __( 'This action plan changed in another tab. Reload it before saving again.', 'pop-progress-tracker' ), array( 'status' => 409, 'currentRevision' => (int) $row['revision'] ) );
		}

		$title = sanitize_text_field( $params['title'] ?? $row['title'] );
		if ( '' === $title ) {
			return new WP_Error( 'popp_missing_title', __( 'Enter a name for the action plan.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		}
		$raw_data = is_array( $params['data'] ?? null ) ? $params['data'] : array();
		$valid    = $this->validate_plan_date( $raw_data['weekOneEnding'] ?? '' );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$existing = $this->sanitize_data( json_decode( $row['plan_data'], true ) ?: array() );
		$data     = $this->sanitize_data( $raw_data );
		foreach ( $existing['weeks'] as $index => $week ) {
			if ( 'submitted' === $week['status'] ) {
				$data['weeks'][ $index ] = $week;
			} else {
				$data['weeks'][ $index ]['status'] = 'draft';
				$data['weeks'][ $index ]['score']  = null;
			}
		}
		if ( array_filter( $existing['weeks'], static function ( $week ) { return 'submitted' === $week['status']; } ) ) {
			// Submitted results are historical records. Keep their schedule, activity
			// count, activity minimums, and Rocks immutable while leaving labels editable.
			$data['weekOneEnding'] = $existing['weekOneEnding'];
			$locked_activities     = array();
			foreach ( $existing['activities'] as $index => $activity ) {
				$locked_activities[] = array(
					'name'    => $data['activities'][ $index ]['name'] ?? $activity['name'],
					'minimum' => $activity['minimum'],
				);
			}
			$data['activities'] = $locked_activities;
			$data['rocks']         = $existing['rocks'];
			// Re-normalize weekly actuals against the locked activity list.
			$data = $this->sanitize_data( $data );
			foreach ( $existing['weeks'] as $index => $week ) {
				if ( 'submitted' === $week['status'] ) {
					$data['weeks'][ $index ] = $week;
				}
			}
		}
		if ( ! empty( $params['completeWeek'] ) ) {
			$completed = $this->complete_current_week( $data );
			if ( is_wp_error( $completed ) ) {
				return $completed;
			}
			$data = $completed;
		}

		$status  = $row['status'];
		if ( ! empty( $params['completeWeek'] ) && ! array_filter( $data['weeks'], static function ( $week ) { return 'submitted' !== $week['status']; } ) ) {
			$status = 'completed';
		}
		$updated = $wpdb->update(
			POPP_Database::table(),
			array(
				'title'      => $title,
				'status'     => $status,
				'plan_data'  => wp_json_encode( $data ),
				'revision'   => (int) $row['revision'] + 1,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row['id'], 'user_id' => get_current_user_id(), 'revision' => (int) $row['revision'] ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d', '%d', '%d' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'popp_write_conflict', __( 'This action plan changed before it could be saved. Reload and try again.', 'pop-progress-tracker' ), array( 'status' => 409 ) );
		}

		return $this->show_by_id( (int) $row['id'] );
	}

	public function archive( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$wpdb->update( POPP_Database::table(), array( 'status' => 'archived', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $request['id'] ), 'user_id' => get_current_user_id() ) );
		return rest_ensure_response( array( 'archived' => true ) );
	}

	private function find( int $id, ?int $user_id = null ): ?array {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . POPP_Database::table() . " WHERE id = %d AND user_id = %d AND status IN ('active', 'completed', 'archived')", $id, $user_id ), ARRAY_A );
		return $row ?: null;
	}

	private function show_by_id( int $id ) {
		$owner = $this->owner_id( $id );
		$row   = $owner ? $this->find( $id, $owner ) : null;
		if ( ! $row ) {
			return new WP_Error( 'popp_missing_plan', __( 'Action plan not found.', 'pop-progress-tracker' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->hydrate( $row ) );
	}

	private function summary( array $row ): array {
		return array(
			'id' => (int) $row['id'], 'title' => $row['title'], 'status' => $row['status'], 'revision' => (int) $row['revision'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
		);
	}

	private function hydrate( array $row ): array {
		$owner = get_userdata( (int) $row['user_id'] );
		return array_merge(
			$this->summary( $row ),
			array(
				'data'     => $this->sanitize_data( json_decode( $row['plan_data'], true ) ?: array() ),
				'readOnly' => (int) $row['user_id'] !== get_current_user_id(),
				'owner'    => array( 'id' => (int) $row['user_id'], 'name' => $owner ? $owner->display_name : __( 'Unknown user', 'pop-progress-tracker' ) ),
			)
		);
	}

	private function owner_id( int $plan_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . POPP_Database::table() . ' WHERE id = %d AND status IN (\'active\', \'completed\', \'archived\')', $plan_id ) );
	}

	private function visible_student_ids( int $viewer_id ): array {
		return array_values( array_filter( array_map( 'absint', (array) apply_filters( 'popp_action_plan_viewable_student_ids', array(), $viewer_id ) ) ) );
	}

	private function can_view_user( int $viewer_id, int $student_id ): bool {
		return $viewer_id === $student_id || user_can( $viewer_id, 'manage_options' ) || in_array( $student_id, $this->visible_student_ids( $viewer_id ), true );
	}

	private function default_data(): array {
		$next_friday = new DateTimeImmutable( 'next friday', wp_timezone() );
		return array(
			'weekOneEnding' => $next_friday->format( 'Y-m-d' ),
			'activities' => array( array( 'name' => '', 'minimum' => '' ), array( 'name' => '', 'minimum' => '' ), array( 'name' => '', 'minimum' => '' ) ),
			'rocks' => array( '', '', '' ),
			'weeks' => $this->default_weeks(),
		);
	}

	private function default_weeks(): array {
		$weeks = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$weeks[] = array( 'status' => 'draft', 'commitmentCompleted' => null, 'actuals' => array(), 'nextCommitment' => '', 'score' => null );
		}
		return $weeks;
	}

	private function sanitize_data( array $input ): array {
		$defaults = $this->default_data();
		$date     = sanitize_text_field( $input['weekOneEnding'] ?? $defaults['weekOneEnding'] );
		$date_obj = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date || '5' !== $date_obj->format( 'N' ) ) {
			$date = $defaults['weekOneEnding'];
		}

		$activities = array();
		foreach ( array_slice( (array) ( $input['activities'] ?? $defaults['activities'] ), 0, 5 ) as $activity ) {
			$activities[] = array(
				'name'    => sanitize_text_field( is_array( $activity ) ? ( $activity['name'] ?? '' ) : '' ),
				'minimum' => is_array( $activity ) && is_numeric( $activity['minimum'] ?? null ) ? max( 0, (float) $activity['minimum'] ) : '',
			);
		}
		while ( count( $activities ) < 3 ) {
			$activities[] = array( 'name' => '', 'minimum' => '' );
		}

		$rocks = array();
		foreach ( array_slice( (array) ( $input['rocks'] ?? $defaults['rocks'] ), 0, 3 ) as $rock ) {
			$rocks[] = sanitize_textarea_field( (string) $rock );
		}
		while ( count( $rocks ) < 3 ) {
			$rocks[] = '';
		}

		$weeks = array();
		foreach ( $this->default_weeks() as $index => $week_default ) {
			$week    = is_array( $input['weeks'][ $index ] ?? null ) ? $input['weeks'][ $index ] : array();
			$actuals = array();
			foreach ( $activities as $activity_index => $activity ) {
				$value     = $week['actuals'][ $activity_index ] ?? '';
				$actuals[] = is_numeric( $value ) && (float) $value >= 0 ? (float) $value : '';
			}
			$weeks[] = array(
				'status'              => 'submitted' === ( $week['status'] ?? '' ) ? 'submitted' : 'draft',
				'commitmentCompleted' => is_bool( $week['commitmentCompleted'] ?? null ) ? $week['commitmentCompleted'] : null,
				'actuals'             => $actuals,
				'nextCommitment'      => sanitize_textarea_field( $week['nextCommitment'] ?? '' ),
				'score'               => is_numeric( $week['score'] ?? null ) ? min( 100, max( 0, (int) $week['score'] ) ) : null,
			);
		}
		return array( 'weekOneEnding' => $date, 'activities' => $activities, 'rocks' => $rocks, 'weeks' => $weeks );
	}

	private function validate_plan_date( $value ) {
		$date     = sanitize_text_field( (string) $value );
		$date_obj = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date || '5' !== $date_obj->format( 'N' ) ) {
			return new WP_Error( 'popp_invalid_start_date', __( 'Week 1 must end on a Friday.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private function complete_current_week( array $data ) {
		foreach ( $data['activities'] as $activity ) {
			if ( '' === $activity['name'] || '' === $activity['minimum'] ) {
				return new WP_Error( 'popp_incomplete_plan', __( 'Enter a name and weekly minimum for every activity before submitting a week.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
			}
		}
		if ( in_array( '', $data['rocks'], true ) ) {
			return new WP_Error( 'popp_incomplete_plan', __( 'Enter all three Rocks before submitting a week.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		}

		$current = null;
		foreach ( $data['weeks'] as $index => $week ) {
			if ( 'submitted' !== $week['status'] ) {
				$current = $index;
				break;
			}
		}
		if ( null === $current ) {
			return new WP_Error( 'popp_plan_complete', __( 'All 12 weeks have already been submitted.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		}

		$week = $data['weeks'][ $current ];
		if ( ! is_bool( $week['commitmentCompleted'] ) || '' === $week['nextCommitment'] || in_array( '', $week['actuals'], true ) ) {
			return new WP_Error( 'popp_incomplete_week', __( 'Complete the commitment status, every activity result, and the next commitment before submitting.', 'pop-progress-tracker' ), array( 'status' => 400 ) );
		}
		$hits = $week['commitmentCompleted'] ? 1 : 0;
		foreach ( $week['actuals'] as $index => $actual ) {
			if ( $actual >= $data['activities'][ $index ]['minimum'] ) {
				$hits++;
			}
		}
		$hits++;
		$week['score']          = (int) ceil( ( $hits / ( count( $data['activities'] ) + 2 ) ) * 100 );
		$week['status']         = 'submitted';
		$data['weeks'][ $current ] = $week;
		return $data;
	}
}

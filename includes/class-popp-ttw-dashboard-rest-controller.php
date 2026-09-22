<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POPP_TTW_Dashboard_REST_Controller {
	private string $namespace = 'popp/v2';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/ttw-dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/ttw-dashboard/trailing-weeks',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_ttw' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/ttw-dashboard/team-scorecard',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_scorecard' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	public function can_access() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'popp_ttw_login_required',
				__( 'Please log in to access this dashboard.', 'pop-progress-tracker' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::user_can_access( get_current_user_id() ) ) {
			return new WP_Error(
				'popp_ttw_forbidden',
				__( "You don't have enough permission to access this dashboard, contact to your administrator.", 'pop-progress-tracker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function user_can_access( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$allowed_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						'absint',
						(array) apply_filters( 'popp_ttw_dashboard_allowed_user_ids', array(), $user_id )
					)
				)
			)
		);

		return in_array( $user_id, $allowed_ids, true );
	}

	public function show() {
		$row = $this->get_or_create();
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return rest_ensure_response( $this->hydrate( $row ) );
	}

	public function update_ttw( WP_REST_Request $request ) {
		return $this->update_section( $request, 'ttw' );
	}

	public function update_scorecard( WP_REST_Request $request ) {
		return $this->update_section( $request, 'scorecard' );
	}

	private function update_section( WP_REST_Request $request, string $section ) {
		global $wpdb;

		$row = $this->get_or_create();
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$params          = (array) $request->get_json_params();
		$revision_column = 'ttw' === $section ? 'ttw_revision' : 'scorecard_revision';
		$data_column     = 'ttw' === $section ? 'ttw_data' : 'scorecard_data';
		$revision        = isset( $params['revision'] ) ? absint( $params['revision'] ) : 0;
		if ( $revision !== (int) $row[ $revision_column ] ) {
			return new WP_Error(
				'popp_ttw_write_conflict',
				__( 'This dashboard changed in another browser tab. Reload it before saving again.', 'pop-progress-tracker' ),
				array( 'status' => 409, 'currentRevision' => (int) $row[ $revision_column ] )
			);
		}

		$raw = is_array( $params['data'] ?? null ) ? $params['data'] : array();
		if ( strlen( wp_json_encode( $raw ) ) > 500000 ) {
			return new WP_Error( 'popp_ttw_too_large', __( 'This dashboard contains too much data.', 'pop-progress-tracker' ), array( 'status' => 413 ) );
		}
		$data = 'ttw' === $section ? $this->sanitize_ttw( $raw ) : $this->sanitize_scorecard( $raw );

		$updated = $wpdb->update(
			POPP_Database::ttw_dashboard_table(),
			array(
				$data_column     => wp_json_encode( $data ),
				$revision_column => $revision + 1,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array(
				'id'              => (int) $row['id'],
				'user_id'         => get_current_user_id(),
				$revision_column => $revision,
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d', '%d' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'popp_ttw_write_conflict', __( 'The dashboard could not be saved. Reload it and try again.', 'pop-progress-tracker' ), array( 'status' => 409 ) );
		}

		$fresh = $this->find();
		return $fresh ? rest_ensure_response( $this->hydrate( $fresh ) ) : new WP_Error( 'popp_ttw_missing_dashboard', __( 'Dashboard not found.', 'pop-progress-tracker' ), array( 'status' => 404 ) );
	}

	private function get_or_create() {
		global $wpdb;

		$row = $this->find();
		if ( $row ) {
			return $row;
		}

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			POPP_Database::ttw_dashboard_table(),
			array(
				'user_id'              => get_current_user_id(),
				'ttw_data'             => wp_json_encode( $this->default_ttw() ),
				'scorecard_data'       => wp_json_encode( $this->default_scorecard() ),
				'ttw_revision'         => 1,
				'scorecard_revision'   => 1,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			// A simultaneous first request may have created the user's row.
			$row = $this->find();
			if ( $row ) {
				return $row;
			}
			return new WP_Error( 'popp_ttw_database_error', __( 'The dashboard could not be created.', 'pop-progress-tracker' ), array( 'status' => 500 ) );
		}
		$row = $this->find();
		return $row ?: new WP_Error( 'popp_ttw_database_error', __( 'The dashboard could not be loaded after it was created.', 'pop-progress-tracker' ), array( 'status' => 500 ) );
	}

	private function find(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . POPP_Database::ttw_dashboard_table() . ' WHERE user_id = %d LIMIT 1', get_current_user_id() ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private function hydrate( array $row ): array {
		return array(
			'id'                => (int) $row['id'],
			'ttwRevision'       => (int) $row['ttw_revision'],
			'scorecardRevision' => (int) $row['scorecard_revision'],
			'ttw'               => $this->sanitize_ttw( json_decode( $row['ttw_data'], true ) ?: array() ),
			'scorecard'         => $this->sanitize_scorecard( json_decode( $row['scorecard_data'], true ) ?: array() ),
			'createdAt'         => $row['created_at'],
			'updatedAt'         => $row['updated_at'],
		);
	}

	private function default_ttw(): array {
		$weeks = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$weeks[] = 'Week ' . $i;
		}
		$metrics = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$metrics[] = array( 'id' => 'metric-' . $i, 'who' => '', 'name' => '', 'goal' => '', 'values' => array_fill( 0, 12, '' ) );
		}
		return array( 'weeks' => $weeks, 'metrics' => $metrics, 'executionWho' => '' );
	}

	private function default_scorecard(): array {
		$reps = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$reps[] = array( 'id' => 'rep-' . $i, 'name' => '', 'tactics' => array( '', '', '' ), 'kept' => '', 'newCommitment' => '', 'rock' => '', 'notes' => '' );
		}
		return array( 'tactics' => array( 'Tactic 1', 'Tactic 2', 'Tactic 3' ), 'reps' => $reps );
	}

	private function sanitize_ttw( array $input ): array {
		$defaults = $this->default_ttw();
		$weeks   = array();
		foreach ( array_values( array_slice( (array) ( $input['weeks'] ?? array() ), 0, 12 ) ) as $index => $week ) {
			$label   = substr( sanitize_text_field( (string) $week ), 0, 40 );
			$weeks[] = '' !== $label ? $label : $defaults['weeks'][ $index ];
		}
		while ( count( $weeks ) < 12 ) {
			$weeks[] = $defaults['weeks'][ count( $weeks ) ];
		}

		$metrics = array();
		$ids     = array();
		foreach ( array_values( array_slice( (array) ( $input['metrics'] ?? array() ), 0, 50 ) ) as $index => $metric ) {
			if ( ! is_array( $metric ) ) {
				continue;
			}
			$id = substr( sanitize_key( (string) ( $metric['id'] ?? '' ) ), 0, 60 );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'metric-' . ( $index + 1 ) . '-' . wp_generate_password( 6, false, false );
			}
			$ids[ $id ] = true;
			$values     = array();
			foreach ( array_values( array_slice( (array) ( $metric['values'] ?? array() ), 0, 12 ) ) as $value ) {
				$values[] = $this->number_or_blank( $value );
			}
			while ( count( $values ) < 12 ) {
				$values[] = '';
			}
			$metrics[] = array(
				'id'     => $id,
				'who'    => substr( sanitize_text_field( (string) ( $metric['who'] ?? '' ) ), 0, 100 ),
				'name'   => substr( sanitize_text_field( (string) ( $metric['name'] ?? '' ) ), 0, 190 ),
				'goal'   => $this->number_or_blank( $metric['goal'] ?? '' ),
				'values' => $values,
			);
		}
		if ( empty( $metrics ) ) {
			$metrics[] = $defaults['metrics'][0];
		}

		return array(
			'weeks'        => $weeks,
			'metrics'      => $metrics,
			'executionWho' => substr( sanitize_text_field( (string) ( $input['executionWho'] ?? '' ) ), 0, 100 ),
		);
	}

	private function sanitize_scorecard( array $input ): array {
		$defaults = $this->default_scorecard();
		$tactics  = array();
		foreach ( array_values( array_slice( (array) ( $input['tactics'] ?? array() ), 0, 3 ) ) as $index => $tactic ) {
			$label     = substr( sanitize_text_field( (string) $tactic ), 0, 100 );
			$tactics[] = '' !== $label ? $label : $defaults['tactics'][ $index ];
		}
		while ( count( $tactics ) < 3 ) {
			$tactics[] = $defaults['tactics'][ count( $tactics ) ];
		}

		$reps = array();
		$ids  = array();
		foreach ( array_values( array_slice( (array) ( $input['reps'] ?? array() ), 0, 50 ) ) as $index => $rep ) {
			if ( ! is_array( $rep ) ) {
				continue;
			}
			$id = substr( sanitize_key( (string) ( $rep['id'] ?? '' ) ), 0, 60 );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'rep-' . ( $index + 1 ) . '-' . wp_generate_password( 6, false, false );
			}
			$ids[ $id ] = true;
			$values     = array();
			foreach ( array_values( array_slice( (array) ( $rep['tactics'] ?? array() ), 0, 3 ) ) as $value ) {
				$values[] = $this->number_or_blank( $value );
			}
			while ( count( $values ) < 3 ) {
				$values[] = '';
			}
			$kept = sanitize_key( (string) ( $rep['kept'] ?? '' ) );
			$reps[] = array(
				'id'            => $id,
				'name'          => substr( sanitize_text_field( (string) ( $rep['name'] ?? '' ) ), 0, 100 ),
				'tactics'       => $values,
				'kept'          => in_array( $kept, array( 'yes', 'no' ), true ) ? $kept : '',
				'newCommitment' => substr( sanitize_textarea_field( (string) ( $rep['newCommitment'] ?? '' ) ), 0, 500 ),
				'rock'          => substr( sanitize_textarea_field( (string) ( $rep['rock'] ?? '' ) ), 0, 500 ),
				'notes'         => substr( sanitize_textarea_field( (string) ( $rep['notes'] ?? '' ) ), 0, 1000 ),
			);
		}
		if ( empty( $reps ) ) {
			$reps[] = $defaults['reps'][0];
		}

		return array( 'tactics' => $tactics, 'reps' => $reps );
	}

	private function number_or_blank( $value ) {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$number = (float) $value;
		return max( 0, min( 999999999, $number ) );
	}
}

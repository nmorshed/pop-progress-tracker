<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POPP_Plugin {
	private static ?POPP_Plugin $instance = null;

	public static function instance(): POPP_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		POPP_Database::maybe_upgrade();
		add_action( 'rest_api_init', array( new POPP_REST_Controller(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new POPP_Playbook_REST_Controller(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new POPP_TTW_Dashboard_REST_Controller(), 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'protect_private_rest_responses' ), 10, 3 );
		add_action( 'wp', array( $this, 'protect_shortcode_pages' ) );
		add_shortcode( 'pop_sales_action_plan', array( $this, 'render_shortcode' ) );
		add_shortcode( 'pop_sales_playbook_builder', array( $this, 'render_playbook_shortcode' ) );
		add_shortcode( 'pop_ttw_dashboard', array( $this, 'render_ttw_dashboard_shortcode' ) );
	}

	public function render_ttw_dashboard_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			$redirect = get_permalink() ?: home_url( '/' );
			return '<div class="popp-login-required"><a href="' . esc_url( wp_login_url( $redirect ) ) . '">' . esc_html__( 'Please log in to access this dashboard.', 'pop-progress-tracker' ) . '</a></div>';
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		if ( ! POPP_TTW_Dashboard_REST_Controller::user_can_access( get_current_user_id() ) ) {
			return '<div class="popp-permission-required">' . esc_html__( "You don't have enough permission to access this dashboard, contact to your administrator.", 'pop-progress-tracker' ) . '</div>';
		}

		$script_path = POPP_DIR . 'assets/js/popp-ttw-dashboard.js';
		$style_path  = POPP_DIR . 'assets/css/popp-ttw-dashboard.css';
		$script_ver  = file_exists( $script_path ) ? (string) filemtime( $script_path ) : POPP_VERSION;
		$style_ver   = file_exists( $style_path ) ? (string) filemtime( $style_path ) : POPP_VERSION;

		wp_enqueue_style( 'popp-ttw-dashboard', POPP_URL . 'assets/css/popp-ttw-dashboard.css', array(), $style_ver );
		wp_enqueue_script( 'popp-ttw-dashboard', POPP_URL . 'assets/js/popp-ttw-dashboard.js', array( 'wp-element' ), $script_ver, true );
		wp_add_inline_script(
			'popp-ttw-dashboard',
			'window.POPP_TTW_DASHBOARD = ' . wp_json_encode(
				array(
					'root'  => esc_url_raw( rest_url( 'popp/v2/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);

		return '<div class="popp-ttw-dashboard" data-popp-ttw-dashboard aria-live="polite"><p>' . esc_html__( 'Loading your trailing twelve week dashboard…', 'pop-progress-tracker' ) . '</p></div>';
	}

	public function render_playbook_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="popp-login-required">' . esc_html__( 'Please log in to access your sales playbooks.', 'pop-progress-tracker' ) . '</div>';
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		nocache_headers();
		$script = POPP_DIR . 'assets/js/popp-playbook.js';
		$style  = POPP_DIR . 'assets/css/popp-playbook.css';
		wp_enqueue_style( 'popp-playbook', POPP_URL . 'assets/css/popp-playbook.css', array(), file_exists( $style ) ? (string) filemtime( $style ) : POPP_VERSION );
		wp_enqueue_script( 'popp-playbook', POPP_URL . 'assets/js/popp-playbook.js', array( 'wp-element' ), file_exists( $script ) ? (string) filemtime( $script ) : POPP_VERSION, true );
		wp_add_inline_script( 'popp-playbook', 'window.POPP_PLAYBOOK=' . wp_json_encode( array( 'root' => esc_url_raw( rest_url( 'popp/v2/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ) . ';', 'before' );
		return '<div class="popp-playbook" data-popp-playbook aria-live="polite"><p>' . esc_html__( 'Loading your sales playbooks…', 'pop-progress-tracker' ) . '</p></div>';
	}

	public function render_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="popp-login-required">' . esc_html__( 'Please log in to access your sales action plan.', 'pop-progress-tracker' ) . '</div>';
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		$script_path = POPP_DIR . 'assets/js/popp-action-plan.js';
		$style_path  = POPP_DIR . 'assets/css/popp-action-plan.css';
		$script_ver  = file_exists( $script_path ) ? (string) filemtime( $script_path ) : POPP_VERSION;
		$style_ver   = file_exists( $style_path ) ? (string) filemtime( $style_path ) : POPP_VERSION;

		wp_enqueue_style( 'popp-action-plan', POPP_URL . 'assets/css/popp-action-plan.css', array(), $style_ver );
		wp_enqueue_script( 'popp-action-plan', POPP_URL . 'assets/js/popp-action-plan.js', array( 'wp-element' ), $script_ver, true );
		wp_add_inline_script(
			'popp-action-plan',
			'window.POPP_ACTION_PLAN = ' . wp_json_encode(
				array(
					'root'        => esc_url_raw( rest_url( 'popp/v2/' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'canViewTeam' => user_can( get_current_user_id(), 'manage_options' ) || ! empty( apply_filters( 'popp_action_plan_viewable_student_ids', array(), get_current_user_id() ) ),
				)
			) . ';',
			'before'
		);

		return '<div class="popp-action-plan" data-popp-action-plan aria-live="polite"><p>' . esc_html__( 'Loading your sales action plans…', 'pop-progress-tracker' ) . '</p></div>';
	}

	public function protect_shortcode_pages(): void {
		if ( ! is_user_logged_in() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ( ! has_shortcode( (string) $post->post_content, 'pop_sales_action_plan' ) && ! has_shortcode( (string) $post->post_content, 'pop_sales_playbook_builder' ) && ! has_shortcode( (string) $post->post_content, 'pop_ttw_dashboard' ) ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	public function protect_private_rest_responses( $response, $server, WP_REST_Request $request ) {
		if ( 0 !== strpos( $request->get_route(), '/popp/v2/' ) ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}
}

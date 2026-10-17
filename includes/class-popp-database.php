<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the one durable record used by each sales action plan.
 *
 * Keeping a complete plan in a single versioned record makes each save atomic and
 * lets the REST API reject writes originating from an older browser tab.
 */
class POPP_Database {
	public const VERSION = '4.1.1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'popp_action_plans';
	}

	public static function playbook_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'popp_sales_playbooks';
	}

	public static function ttw_dashboard_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'popp_ttw_dashboards';
	}

	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			title varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			plan_data longtext NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_status_updated (user_id,status,updated_at)
		) $charset_collate;";
		$playbooks       = self::playbook_table();
		$playbook_sql    = "CREATE TABLE {$playbooks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			title varchar(190) NOT NULL,
			segment_name varchar(190) NOT NULL DEFAULT '',
			company_name varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			current_step tinyint(3) unsigned NOT NULL DEFAULT 1,
			completion_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
			playbook_data longtext NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_status_updated (user_id,status,updated_at)
		) $charset_collate;";
		$ttw_dashboards  = self::ttw_dashboard_table();
		$ttw_sql         = "CREATE TABLE {$ttw_dashboards} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			ttw_data longtext NOT NULL,
			scorecard_data longtext NOT NULL,
			ttw_revision bigint(20) unsigned NOT NULL DEFAULT 1,
			scorecard_revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id)
		) $charset_collate;";

		dbDelta( $sql );
		dbDelta( $playbook_sql );
		dbDelta( $ttw_sql );
		update_option( 'popp_db_version', self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( 'popp_db_version' );
		if ( self::VERSION !== $installed ) {
			self::activate();
			if ( $installed && version_compare( $installed, '4.1.1', '<' ) ) {
				self::decode_legacy_text();
			}
		}
	}

	/**
	 * Before 4.1.1 text was saved through sanitize_text_field(), which stored "<"
	 * as "&lt;" and "&" as "&amp;". Decode those rows once so users see what they typed.
	 */
	private static function decode_legacy_text(): void {
		global $wpdb;
		$tables = array(
			self::table()               => array( 'title', 'plan_data' ),
			self::playbook_table()      => array( 'title', 'segment_name', 'company_name', 'playbook_data' ),
			self::ttw_dashboard_table() => array( 'ttw_data', 'scorecard_data' ),
		);
		foreach ( $tables as $table => $columns ) {
			$where = implode( ' OR ', array_map( static function ( $column ) { return "`{$column}` LIKE '%&%'"; }, $columns ) );
			$ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $ids as $id ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row ) {
					continue;
				}
				$changes = array();
				foreach ( $columns as $column ) {
					$value = (string) $row[ $column ];
					if ( '_data' === substr( $column, -5 ) ) {
						$decoded = json_decode( $value, true );
						$fixed   = is_array( $decoded ) ? wp_json_encode( POPP_Text::decode_legacy( $decoded ) ) : $value;
					} else {
						$fixed = POPP_Text::decode_legacy( $value );
					}
					if ( $fixed !== $value ) {
						$changes[ $column ] = $fixed;
					}
				}
				if ( $changes ) {
					$wpdb->update( $table, $changes, array( 'id' => (int) $id ) );
				}
			}
		}
	}
}

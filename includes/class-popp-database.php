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
	public const VERSION = '3.0.0';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'popp_action_plans';
	}

	public static function playbook_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'popp_sales_playbooks';
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

		dbDelta( $sql );
		dbDelta( $playbook_sql );
		update_option( 'popp_db_version', self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( 'popp_db_version' ) ) {
			self::activate();
		}
	}
}

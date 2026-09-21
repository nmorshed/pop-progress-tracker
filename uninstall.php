<?php
/**
 * Data is deliberately retained on uninstall.
 * Define POPP_REMOVE_DATA as true before uninstalling to remove plugin tables.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'POPP_REMOVE_DATA' ) || true !== POPP_REMOVE_DATA ) {
	return;
}

global $wpdb;
$tables = array(
	$wpdb->prefix . 'popp_sales_playbooks',
	$wpdb->prefix . 'popp_action_plans',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
delete_option( 'popp_db_version' );

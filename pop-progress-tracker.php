<?php
/**
 * Plugin Name: POP Sales Tools
 * Description: Private, cache-safe Sales Action Plan and Sales Playbook Builder tools for logged-in WordPress users.
 * Version: 3.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: POP
 * Text Domain: pop-progress-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POPP_VERSION', '3.0.0' );
define( 'POPP_FILE', __FILE__ );
define( 'POPP_DIR', plugin_dir_path( __FILE__ ) );
define( 'POPP_URL', plugin_dir_url( __FILE__ ) );

require_once POPP_DIR . 'includes/class-popp-database.php';
require_once POPP_DIR . 'includes/class-popp-rest-controller.php';
require_once POPP_DIR . 'includes/class-popp-playbook-rest-controller.php';
require_once POPP_DIR . 'includes/class-popp-plugin.php';

register_activation_hook( __FILE__, array( 'POPP_Database', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		POPP_Plugin::instance();
	}
);

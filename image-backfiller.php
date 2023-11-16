<?php
/**
 * The 1 bootstrap file.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @author      WordPress.com Special Projects
 * @license     GPL-3.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             WP-CLI Image Backfiller
 * Plugin URI:              https://wpspecialprojects.wordpress.com
 * Description:             WP-CLI utility for pulling in media after an import.
 * Version:                 1.0.0
 * Requires at least:       6.2
 * Tested up to:            6.2
 * Requires PHP:            8.0
 * Author:                  WordPress.com Special Projects
 * Author URI:              https://wpspecialprojects.wordpress.com
 * License:                 GPL v3 or later
 * License URI:             https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:             wpcomsp-image-backfiller
 * Domain Path:             /languages
 **/

 if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Define plugin constants.
function_exists( 'get_plugin_data' ) || require_once ABSPATH . 'wp-admin/includes/plugin.php';
define( 'WPCOMSP_51_BACKFILL_METADATA', get_plugin_data( __FILE__, false, false ) );

define( 'WPCOMSP_51_BACKFILL_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPCOMSP_51_BACKFILL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCOMSP_51_BACKFILL_URL', plugin_dir_url( __FILE__ ) );

// Load plugin translations so they are available even for the error admin notices.
add_action(
	'init',
	static function() {
		load_plugin_textdomain(
			WPCOMSP_51_BACKFILL_METADATA['TextDomain'],
			false,
			dirname( WPCOMSP_51_BACKFILL_BASENAME ) . WPCOMSP_51_BACKFILL_METADATA['DomainPath']
		);
	}
);

// Load the autoloader.
if ( ! is_file( WPCOMSP_51_BACKFILL_PATH . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function() {
			$message      = __( 'It seems like <strong>1</strong> is corrupted. Please reinstall!', 'wpcomsp-image-backfiller' );
			$html_message = wp_sprintf( '<div class="error notice wpcomsp-image-backfiller-error">%s</div>', wpautop( $message ) );
			echo wp_kses_post( $html_message );
		}
	);
	return;
}
require_once WPCOMSP_51_BACKFILL_PATH . '/vendor/autoload.php';

// Initialize the plugin if system requirements check out.
$wpcomsp_51_backfill_requirements = validate_plugin_requirements( WPCOMSP_51_BACKFILL_BASENAME );
define( 'WPCOMSP_51_BACKFILL_REQUIREMENTS', $wpcomsp_51_backfill_requirements );

if ( $wpcomsp_51_backfill_requirements instanceof WP_Error ) {
	add_action(
		'admin_notices',
		static function() use ( $wpcomsp_51_backfill_requirements ) {
			$html_message = wp_sprintf( '<div class="error notice wpcomsp-image-backfiller-error">%s</div>', $wpcomsp_51_backfill_requirements->get_error_message() );
			echo wp_kses_post( $html_message );
		}
	);
} else {
	require_once WPCOMSP_51_BACKFILL_PATH . 'functions.php';
	add_action( 'plugins_loaded', array( wpcomsp_51_backfill_get_plugin_instance(), 'maybe_initialize' ) );
}

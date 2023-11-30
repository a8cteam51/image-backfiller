<?php

use WPCOMSpecialProjects\ImageBackfiller\Plugin;

defined( 'ABSPATH' ) || exit;

// region

/**
 * Returns the plugin's main class instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function wpcomsp_51_backfill_get_plugin_instance(): Plugin {
	return Plugin::get_instance();
}

/**
 * Returns the plugin's slug.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function wpcomsp_51_backfill_get_plugin_slug(): string {
	return sanitize_key( WPCOMSP_51_BACKFILL_METADATA['TextDomain'] );
}

// endregion

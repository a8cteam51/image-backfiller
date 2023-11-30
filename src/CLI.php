<?php

namespace WPCOMSpecialProjects\ImageBackfiller;

use DOMDocument;
use WP_CLI;
use WP_CLI_Command;

defined( 'ABSPATH' ) || exit;

/**
 * CLI command class.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class CLI extends WP_CLI_Command {
	protected array $extensions = array();
	protected bool $verbose;

	/**
	 * Sets configurations that are shared across each subcommand and pass
	 *
	 * @return void
	 */
	private function config() {
		// phpcs:disable WordPress.PHP.IniSet.display_errors_Blacklisted,WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting,WordPress.PHP.IniSet.memory_limit_Blacklisted,WordPress.PHP.IniSet.display_errors_Disallowed,WordPress.PHP.IniSet.memory_limit_Disallowed -- We need to set these for the CLI.
		ini_set( 'display_errors', true );
		error_reporting( E_ALL );
		define( 'UPDATE_REMOTE_MEMCACHED', false );
		define( 'ECLIPSE_SUNRISE_REDIRECT', true );
		define( 'WP_IMPORTING', true );
		set_time_limit( 0 );
		ini_set( 'memory_limit', '1024M' );
		// phpcs:enable WordPress.PHP.IniSet.display_errors_Blacklisted, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting,WordPress.PHP.IniSet.memory_limit_Blacklisted,WordPress.PHP.IniSet.display_errors_Disallowed,WordPress.PHP.IniSet.memory_limit_Disallowed -- We need to set these for the CLI.

		$this->allowed_extensions();
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Following the WP-CLI doc standard
	/**
	 * Imports external images from another site.
	 *
	 * ## OPTIONS
	 *
	 * --domain=<domain>
	 * : Domain to import images from
	 *
	 * [--tags=<tags>]
	 * : The tags to check for in the post content ("all" will check img, input, and a tags)
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - img
	 *   - input
	 *   - a
	 *
	 * [--protocol=<protocol>]
	 * : Protocol of image links to check
	 * ---
	 * default: "both"
	 * options:
	 *   - both
	 *   - http
	 *   - https
	 * ---
	 *
	 * [--num-posts=<num-posts>]
	 * : Number of posts to update. Only posts where an image is backfilled are counted. Default is to process all posts.
	 *
	 * [--posts=<posts>]
	 * : Comma-separated list of post IDs to update.
	 *
	 * [--import-duplicates]
	 * : Import images that already exist in the media library. Default is to update the image link to point to the existing image)
	 *
	 * [--include-params]
	 * : Import images including the parameters in the filename. For example: image.png?w=300 will be imported as imagew300.png.
	 *
	 * [--dry-run]
	 * : Run the command without making any changes
	 *
	 * [--verbose]
	 * : Show detailed logs
	 *
	 * ## EXAMPLES
	 *     # Import images from www.mysite.com
	 *     wp backfill get --domain="www.mysite.com"
	 */ // phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn
	public function get( $args, $assoc_args ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Following the WP-CLI doc standard
		$domain            = $assoc_args['domain'];
		$tags              = $assoc_args['tags'];
		$num_posts         = array_key_exists( 'num-posts', $assoc_args ) ? $assoc_args['num-posts'] : -1;
		$protocol          = array_key_exists( 'protocol', $assoc_args ) ? $assoc_args['protocol'] : 'both';
		$dry_run           = array_key_exists( 'dry-run', $assoc_args );
		$import_duplicates = array_key_exists( 'import-duplicates', $assoc_args );
		$include_params    = array_key_exists( 'include-params', $assoc_args );
		$this->verbose     = array_key_exists( 'verbose', $assoc_args );

		$post_ids = array();
		if ( array_key_exists( 'posts', $assoc_args ) ) {
			$post_ids = explode( ',', $assoc_args['posts'] );
		}

		$this->config();

		WP_CLI::log( 'Backfilling images into ' . home_url() . PHP_EOL );

		$this->verbose_log( 'Options:' );
		$this->verbose_log( " -- Domain: $domain" );
		$this->verbose_log( " -- Tags: $tags" );
		$this->verbose_log( " -- Protocol: $protocol" );
		$this->verbose_log( " -- Num Posts: $num_posts" );
		$this->verbose_log( sprintf( ' -- Import Duplicates: %s', $this->bool_to_string( $import_duplicates ) ) );
		$this->verbose_log( sprintf( ' -- Include Params: %s', $this->bool_to_string( $include_params ) ) );
		$this->verbose_log( sprintf( ' -- Dry Run: %s', $this->bool_to_string( $dry_run ) ) );
		$this->verbose_log( sprintf( ' -- Verbose: %s\n', $this->bool_to_string( $this->verbose ) ) );

		global $wpdb;

		$wildcard = '%';
		$like     = $wildcard . $wpdb->esc_like( $domain ) . $wildcard;

		if ( empty( $post_ids ) ) {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID
		FROM
			$wpdb->posts
		WHERE
			post_type = 'post'
			AND post_content LIKE %s",
					$like
				)
			);}
		WP_CLI::log( 'Processing ' . count( $post_ids ) . " posts\n" );
		$count              = 0;
		$processed_count    = 0;
		$uploaded_image_src = '';
		$imported_images    = array();

		foreach ( $post_ids as $post_id ) {
			$post_content = get_post( $post_id )->post_content;
			$processed    = false;
			++$count;

			$this->verbose_log( "Processing post $count (#$post_id)\n" );

			if ( empty( $post_content ) ) {
				$this->verbose_log( "	 -- Skipping #$post_id. No post content." );
				continue;
			}

			// Load the post DOM.
			$dom_doc = new DOMDocument();

			// The @ is not enough to suppress errors when dealing with libxml,
			// we have to tell it directly how we want to handle errors.
			libxml_use_internal_errors( true );
			@$dom_doc->loadHTML( $post_content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			libxml_use_internal_errors( false );

			for ( $pass = 1; $pass <= 2; $pass++ ) {
				$current_tag = $tags;
				$attr        = '';

				if ( 'all' === $current_tag ) {
					switch ( $pass ) {
						case 1:
							$current_tag = 'img';
							break;
						case 2:
							$current_tag = 'a';
							break;
					}
				}

				switch ( $current_tag ) {
					case 'img':
						$attr = 'src';
						break;
					case 'a':
						$attr = 'href';
						break;
				}

				$images = $dom_doc->getElementsByTagName( $current_tag );
				if ( 0 === $images->length ) {
					$this->verbose_log( "	 -- Skipping #$post_id. No image here." );
					continue;
				}

				// We have to process the images to see if anything will be changed. This flag tracks if that happens;
				$processed = false;

				$this->verbose_log( "Processing post $count (#$post_id)" );

				foreach ( $images as $image ) {
					// Make sure the tag has the attribute we're looking for.
					if ( ! $image->hasAttribute( $attr ) ) {
						$this->verbose_log( " -- Skipping image: . No $attr attribute." );
						continue;
					}

					$image_src = $image->attributes->getNamedItem( $attr )->nodeValue;
					$this->verbose_log( " -- Processing image $image_src" );
					if ( wp_parse_url( $image_src, PHP_URL_HOST ) !== $domain ) {
						$this->verbose_log( "\t-- Wrong domain. Skipping image." );
						continue;
					}

					if ( 'img' === $current_tag && strpos( $image_src, '.html' ) ) {
						$this->verbose_log( "\t-- This is an html file" );
						continue;
					}

					if ( ! $include_params
						// wp_parse_url will return null is there is no query string. It will return false if there is an empty query string.
						// This differentiates between, e.g, `image.png?` and `image.png` and makes sure to strip even empty query strings.
						&& ! is_null( wp_parse_url( $image_src, PHP_URL_QUERY ) ) ) {
						$this->verbose_log( "\t-- Found parameters. Backfilling original image." );
						$image_src = preg_replace( '/\?.*/', '', $image_src );
					}

					$can_download = true;

					if ( ! $import_duplicates ) {
						if ( file_exists( ABSPATH . wp_parse_url( $image_src, PHP_URL_PATH ) ) ) {
							$this->verbose_log( "\t-- Image already exists. Linking to existing image." );
							$full_path          = rtrim( ABSPATH, '/' ) . wp_parse_url( $image_src, PHP_URL_PATH );
							$upload_path        = str_replace( WP_CONTENT_DIR, '', $full_path );
							$uploaded_image_src = content_url( $upload_path );
							$can_download       = false;
						} elseif ( array_key_exists( $image_src, $imported_images ) ) {
							$this->verbose_log( "\t-- Image already imported. Linking to imported image." );
							$uploaded_image_src = $imported_images[ $image_src ];
							$can_download       = false;
						}
					}

					if ( $can_download ) {
						$this->verbose_log( "\t-- Downloading image." );
						add_filter(
							'upload_mimes',
							function ( $mimes ) {
								$mimes['placeholder'] = 'image/placeholder';
								return $mimes;
							}
						);

						$file_array['tmp_name'] = download_url( $image_src );

						if ( empty( wp_check_filetype( $image_src )['ext'] ) ) {
							$image_src .= '.placeholder';
						}
						$file_array['name'] = basename( $image_src );
						$attachment_id      = media_handle_sideload( $file_array, $post_id );

						$uploaded_image_src = wp_get_attachment_url( $attachment_id );

						if ( empty( $uploaded_image_src ) ) {
							WP_CLI::warning( " -- Image download failed for '$image_src' on post #$post_id" );
							if ( is_wp_error( $attachment_id ) ) {
								WP_CLI::log( $attachment_id );
							}
							continue;
						}

						update_post_meta(
							$attachment_id,
							'_added_via_script_backup_meta',
							array(
								'old_url' => $image_src,
								'new_url' => $uploaded_image_src,
							)
						);

						if ( false !== strpos( $image_src, '.placeholder' ) ) {
							$image_src = str_replace( '.placeholder', '', $image_src );
						}
					}

					// Handle the case where the image was not downloaded.
					// For example, if $import_duplicates and $can_download are both false
					if ( empty ( $uploaded_image_src ) ) {
						$this->verbose_log( " -- No uploaded image src for $image_src" );
						continue;
					}

					$processed                     = true;
					$imported_images[ $image_src ] = $uploaded_image_src;

					$this->verbose_log( " -- Updating post with new image URL: $uploaded_image_src" );
					$post_content = str_replace( $image_src, $uploaded_image_src, $post_content );

					if ( ! $dry_run ) {
						$wpdb->update( $wpdb->posts, array( 'post_content' => $post_content ), array( 'ID' => $post_id ) );
					}
				}
				usleep( 5000 );

				// Only one pass is needed if we're not checking for all tags.
				if ( 'all' !== $tags ) {
					break;
				}
			}
			$this->verbose_log( " -- Post $count (#$post_id) processed." );
			$this->verbose_log( "\n========================================================================================\n" );

			if ( $processed ) {
				++$processed_count;
			}
			if ( $num_posts > 0 && $processed_count >= $num_posts ) {
				$this->verbose_log( " -- Stopping after $count posts." );
				break;
			}
		}

		WP_CLI::success( "Complete! $count posts processed, $processed_count updated.\n" );
	}

	/**
	 * Allowed extensions for media upload.
	 *
	 * @return void
	 */
	protected function allowed_extensions() {
		$allowed_extensions = array_keys( get_allowed_mime_types() );

		foreach ( $allowed_extensions as $extension ) {
			$this->extensions = array_merge( $this->extensions, explode( '|', $extension ) );
		}
	}

	/**
	 * Check for extension whether it is allowed as an attachment in Media Library.
	 *
	 * @param string $ext File extension.
	 *
	 * @return boolean
	 */
	protected function is_extension_allowed_as_attachment( string $ext ): bool {
		if ( in_array( $ext, $this->extensions, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Generate the Regular Expression for the content tag.
	 *
	 * @param string $content  The string to check.
	 * @param string $tag      The tag to check for (img, input, a).
	 * @param string $protocol The protocol to check for. Only required for a tags (http, https, both - default: both).
	 * @param string $url      The url to check for. Only required for a tags.
	 *
	 * @return string | bool The regular expression. False if invalid parameters.
	 */
	protected function get_regexp( string $content, string $tag, string $protocol = 'both', string $url = '' ) {
		if ( empty( $content ) || empty( $tag ) ) {
			return false;
		}
		if ( 'a' === $tag && empty( $url ) ) {
			return false;
		}
		if ( 'both' === $protocol ) {
			$protocol = 'https?';
		}
		$protocol .= '://';
		switch ( $tag ) {
			case 'img':
				return '#<img(.*?)>#si';
			case 'a':
				return '#<a(\s+)href="' . $protocol . $url . '/(.*?)</a>#s';
			case 'input':
				return '#<input(.*?)>#si';
			default:
				return false;
		}
	}

	/**
	 * Output verbose log.
	 *
	 * @param string $log The log to output.
	 *
	 * @return void
	 */
	protected function verbose_log( string $log ) {
		if ( $this->verbose ) {
			WP_CLI::log( $log );
		}
	}

	/**
	 * Convert boolean to string
	 *
	 * @param boolean $boolean The boolean to convert.
	 *
	 * @return string
	 */
	protected function bool_to_string( bool $boolean ): string {
		return $boolean ? 'Yes' : 'No';
	}
}

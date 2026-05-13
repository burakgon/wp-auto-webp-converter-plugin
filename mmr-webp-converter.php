<?php
/**
 * Plugin Name: MMR WebP Converter
 * Plugin URI: https://www.mobilemarketingreads.com/
 * Description: On every post save, scans the post content for &lt;img&gt; references to JPG/PNG/GIF files under /wp-content/uploads, generates a sibling .webp via WP_Image_Editor (Imagick preferred, GD fallback), and rewrites src / srcset / data-src / data-lazy-src / data-srcset attributes to point at the .webp version. Originals stay on disk as fallback. Idempotent: a second save is a no-op when every URL is already .webp or already converted.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: burakgon
 * License: GPL-2.0-or-later
 */

namespace MMR\WebP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION    = '1.0.0';
const QUALITY    = 82;
const META_RUN   = '_mmr_webp_last_run';
const META_STATS = '_mmr_webp_stats';
const LOG_PREFIX = '[mmr-webp] ';
const HOOK_PRIO  = 20;

/**
 * Default post types we touch. Override with the mmr_webp_post_types filter.
 */
function default_post_types(): array {
	return apply_filters( 'mmr_webp_post_types', array( 'post' ) );
}

function should_process( int $post_id, \WP_Post $post ): bool {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return false;
	}
	if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
		return false;
	}
	if ( '' === trim( (string) $post->post_content ) ) {
		return false;
	}
	return in_array( $post->post_type, default_post_types(), true );
}

function uploads_base(): array {
	$u = wp_get_upload_dir();
	return array(
		rtrim( $u['baseurl'], '/' ),
		rtrim( $u['basedir'], '/' ),
	);
}

/**
 * Strip scheme so http/https mismatch between site URL and post content does not break matching.
 */
function strip_scheme( string $url ): string {
	return preg_replace( '#^https?:#i', '', $url );
}

function url_to_local_path( string $url, string $base_url, string $base_dir ): ?string {
	$normalized      = strip_scheme( $url );
	$normalized_base = strip_scheme( $base_url );
	if ( strpos( $normalized, $normalized_base ) !== 0 ) {
		return null;
	}
	$rel = ltrim( substr( $normalized, strlen( $normalized_base ) ), '/' );
	$rel = strtok( $rel, '?#' );
	if ( false === $rel || '' === $rel ) {
		return null;
	}
	// Block directory traversal even though strtok strips most cases.
	if ( strpos( $rel, '..' ) !== false ) {
		return null;
	}
	$path = $base_dir . '/' . $rel;
	return is_file( $path ) ? $path : null;
}

function path_to_webp_path( string $src_path ): string {
	$info = pathinfo( $src_path );
	return $info['dirname'] . '/' . $info['filename'] . '.webp';
}

function url_to_webp_url( string $src_url ): string {
	return preg_replace( '/\.(jpe?g|png|gif)(\?[^#]*)?(#.*)?$/i', '.webp$2$3', $src_url );
}

/**
 * Generate a .webp sibling for the source if missing or outdated.
 * Returns true if the .webp now exists and is current.
 */
function ensure_webp( string $src_path ): bool {
	$webp_path = path_to_webp_path( $src_path );
	if ( $webp_path === $src_path ) {
		// Already a .webp on disk (defensive).
		return true;
	}
	if ( is_file( $webp_path ) && filemtime( $webp_path ) >= filemtime( $src_path ) ) {
		return true;
	}

	$editor = wp_get_image_editor( $src_path );
	if ( is_wp_error( $editor ) ) {
		error_log( LOG_PREFIX . 'open failed: ' . $src_path . ' — ' . $editor->get_error_message() );
		return false;
	}
	$editor->set_quality( QUALITY );
	$saved = $editor->save( $webp_path, 'image/webp' );
	if ( is_wp_error( $saved ) ) {
		error_log( LOG_PREFIX . 'save failed: ' . $src_path . ' — ' . $saved->get_error_message() );
		return false;
	}
	return is_file( $webp_path );
}

/**
 * Rewrite a single URL to its .webp twin if the source is one we can convert.
 * Returns the original URL unchanged on any failure path.
 */
function maybe_rewrite_url( string $url, string $base_url, string $base_dir, array &$stats ): string {
	$url = trim( $url );
	if ( '' === $url ) {
		return $url;
	}
	if ( ! preg_match( '/\.(jpe?g|png|gif)(\?|#|$)/i', $url ) ) {
		return $url;
	}
	$path = url_to_local_path( $url, $base_url, $base_dir );
	if ( null === $path ) {
		++$stats['skipped_external'];
		return $url;
	}
	if ( ! ensure_webp( $path ) ) {
		++$stats['failed'];
		return $url;
	}
	++$stats['converted'];
	return url_to_webp_url( $url );
}

/**
 * Rewrite the value of a single attribute (handles srcset's comma-list form too).
 */
function rewrite_attribute_value( string $value, string $base_url, string $base_dir, array &$stats ): string {
	$is_srcset = ( strpos( $value, ',' ) !== false ) && preg_match( '/\s+\d+(\.\d+)?[wx]/', $value );
	if ( ! $is_srcset ) {
		return maybe_rewrite_url( $value, $base_url, $base_dir, $stats );
	}
	$parts     = preg_split( '/\s*,\s*/', $value );
	$rewritten = array();
	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		$tokens     = preg_split( '/\s+/', $part, 2 );
		$url        = $tokens[0];
		$descriptor = $tokens[1] ?? '';
		$new_url    = maybe_rewrite_url( $url, $base_url, $base_dir, $stats );
		$rewritten[] = $new_url . ( '' !== $descriptor ? ' ' . $descriptor : '' );
	}
	return implode( ', ', $rewritten );
}

function process_content( string $content, array &$stats ): string {
	list( $base_url, $base_dir ) = uploads_base();

	$attrs = array( 'src', 'srcset', 'data-src', 'data-lazy-src', 'data-srcset' );
	foreach ( $attrs as $attr ) {
		$pattern = '/\b(' . preg_quote( $attr, '/' ) . ')\s*=\s*(["\'])(.*?)\2/i';
		$content = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $base_url, $base_dir, &$stats ) {
				$new_value = rewrite_attribute_value( $m[3], $base_url, $base_dir, $stats );
				return $m[1] . '=' . $m[2] . $new_value . $m[2];
			},
			$content
		);
	}

	// Gutenberg block comments embed JSON like {"url":"https://.../image.jpg"}. Keep
	// the comment metadata in sync with the rewritten inner <img>, otherwise the editor
	// flags a "block recovery" mismatch on reopen.
	$content = preg_replace_callback(
		'/("url"\s*:\s*")([^"]+)(")/i',
		function ( $m ) use ( $base_url, $base_dir, &$stats ) {
			$new_value = maybe_rewrite_url( $m[2], $base_url, $base_dir, $stats );
			return $m[1] . $new_value . $m[3];
		},
		$content
	);

	return $content;
}

function on_save( int $post_id, \WP_Post $post ): void {
	if ( ! should_process( $post_id, $post ) ) {
		return;
	}

	$original = $post->post_content;
	$stats    = array(
		'converted'        => 0,
		'failed'           => 0,
		'skipped_external' => 0,
	);
	$updated = process_content( $original, $stats );

	update_post_meta( $post_id, META_RUN, time() );
	update_post_meta( $post_id, META_STATS, $stats );

	if ( $updated === $original ) {
		return;
	}

	// Bypass kses + save_post recursion: rewriting URLs does not introduce new tags.
	global $wpdb;
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_content' => $updated,
			'post_modified' => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		),
		array( 'ID' => $post_id )
	);
	clean_post_cache( $post_id );
}

add_action( 'save_post_post', __NAMESPACE__ . '\on_save', HOOK_PRIO, 2 );

/**
 * WP-CLI: `wp mmr-webp run --post=ID` (or --all) to (re)process posts on demand.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'mmr-webp run',
		function ( $args, $assoc ) {
			$ids = array();
			if ( ! empty( $assoc['post'] ) ) {
				$ids = array_map( 'intval', explode( ',', (string) $assoc['post'] ) );
			} elseif ( ! empty( $assoc['all'] ) ) {
				$ids = get_posts(
					array(
						'post_type'      => default_post_types(),
						'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'numberposts'    => -1,
						'fields'         => 'ids',
						'suppress_filters' => true,
					)
				);
			} else {
				\WP_CLI::error( 'Use --post=ID[,ID,...] or --all.' );
			}

			$totals = array(
				'posts_changed' => 0,
				'converted'     => 0,
				'failed'        => 0,
				'skipped'       => 0,
			);

			foreach ( $ids as $id ) {
				$post = get_post( (int) $id );
				if ( ! $post ) {
					continue;
				}

				$stats = array(
					'converted'        => 0,
					'failed'           => 0,
					'skipped_external' => 0,
				);
				$new = process_content( $post->post_content, $stats );
				if ( $new !== $post->post_content ) {
					global $wpdb;
					$wpdb->update(
						$wpdb->posts,
						array(
							'post_content'      => $new,
							'post_modified'     => current_time( 'mysql' ),
							'post_modified_gmt' => current_time( 'mysql', true ),
						),
						array( 'ID' => $post->ID )
					);
					clean_post_cache( $post->ID );
					++$totals['posts_changed'];
				}
				$totals['converted'] += $stats['converted'];
				$totals['failed']    += $stats['failed'];
				$totals['skipped']   += $stats['skipped_external'];

				\WP_CLI::log(
					sprintf(
						'post %d: converted=%d failed=%d skipped=%d %s',
						$post->ID,
						$stats['converted'],
						$stats['failed'],
						$stats['skipped_external'],
						( $new !== $post->post_content ? '(content updated)' : '(no change)' )
					)
				);

				update_post_meta( $post->ID, META_RUN, time() );
				update_post_meta( $post->ID, META_STATS, $stats );
			}

			\WP_CLI::success(
				sprintf(
					'Done. posts_changed=%d converted=%d failed=%d skipped=%d',
					$totals['posts_changed'],
					$totals['converted'],
					$totals['failed'],
					$totals['skipped']
				)
			);
		},
		array(
			'shortdesc' => 'Run WebP conversion on a post or every post.',
			'synopsis'  => array(
				array(
					'name'        => 'post',
					'type'        => 'assoc',
					'optional'    => true,
					'description' => 'Comma-separated post IDs.',
				),
				array(
					'name'        => 'all',
					'type'        => 'flag',
					'optional'    => true,
					'description' => 'Process every post matching mmr_webp_post_types.',
				),
			),
		)
	);
}

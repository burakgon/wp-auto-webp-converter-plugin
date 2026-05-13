<?php
/**
 * Plugin Name: WP Auto WebP Converter
 * Plugin URI: https://github.com/burakgon/wp-auto-webp-converter-plugin
 * Description: On every post save, scans the post content for &lt;img&gt; references to JPG/PNG/GIF files under /wp-content/uploads, generates a sibling .webp via WP_Image_Editor (Imagick preferred, GD fallback), and rewrites src / srcset / data-src / data-lazy-src / data-srcset attributes to point at the .webp version. Featured images (and every WordPress-generated thumbnail size for them) are also converted and swapped to .webp at render time via the wp_get_attachment_image_src and wp_calculate_image_srcset filters — covering featured image displays, galleries, OG tags, schema.org, and REST API output. Originals stay on disk as fallback. Idempotent: a second save is a no-op when every URL is already .webp or already converted.
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: burakgon
 * Author URI: https://github.com/burakgon
 * License: GPL-2.0-or-later
 * Text Domain: wp-auto-webp-converter-plugin
 */

namespace WPAutoWebP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION    = '1.1.0';
const QUALITY    = 82;
const META_RUN   = '_wp_auto_webp_last_run';
const META_STATS = '_wp_auto_webp_stats';
const LOG_PREFIX = '[wp-auto-webp] ';
const HOOK_PRIO  = 20;

/**
 * Default post types we touch. Override with the wp_auto_webp_post_types filter.
 */
function default_post_types(): array {
	return apply_filters( 'wp_auto_webp_post_types', array( 'post' ) );
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

/**
 * Generate .webp sidecars for the featured image (and every WP-generated intermediate size).
 * Featured images live outside post_content, so the post_content regex cannot reach them — we
 * resolve them via the _thumbnail_id pointer and walk the attachment's metadata 'sizes' array.
 */
function ensure_featured_image_webp( int $post_id, array &$stats ): void {
	$thumb_id = get_post_thumbnail_id( $post_id );
	if ( ! $thumb_id ) {
		return;
	}

	$original_path = get_attached_file( $thumb_id );
	if ( ! $original_path || ! is_file( $original_path ) ) {
		return;
	}
	if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $original_path ) ) {
		return;
	}

	if ( ensure_webp( $original_path ) ) {
		++$stats['converted'];
	} else {
		++$stats['failed'];
	}

	$meta = wp_get_attachment_metadata( $thumb_id );
	if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
		return;
	}

	$base_dir = dirname( $original_path );
	foreach ( $meta['sizes'] as $size_data ) {
		if ( empty( $size_data['file'] ) ) {
			continue;
		}
		$size_path = $base_dir . '/' . $size_data['file'];
		if ( ! is_file( $size_path ) ) {
			continue;
		}
		if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $size_path ) ) {
			continue;
		}
		if ( ensure_webp( $size_path ) ) {
			++$stats['converted'];
		} else {
			++$stats['failed'];
		}
	}
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

	ensure_featured_image_webp( $post_id, $stats );

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
 * Swap an attachment URL to its .webp twin at render time if the sidecar exists on disk.
 * Used by the wp_get_attachment_image_src and wp_calculate_image_srcset filters below to
 * cover everything WP renders through standard attachment functions: featured images,
 * galleries, blocks built via wp_get_attachment_image(), schema.org JSON-LD, OG tags,
 * REST API responses, RSS enclosures, etc.
 */
function maybe_swap_to_webp_url( string $url ): string {
	if ( ! preg_match( '/\.(jpe?g|png|gif)(\?|#|$)/i', $url ) ) {
		return $url;
	}
	list( $base_url, $base_dir ) = uploads_base();
	$path = url_to_local_path( $url, $base_url, $base_dir );
	if ( null === $path ) {
		return $url;
	}
	$webp_path = path_to_webp_path( $path );
	if ( ! is_file( $webp_path ) ) {
		return $url;
	}
	return url_to_webp_url( $url );
}

add_filter(
	'wp_get_attachment_image_src',
	function ( $image ) {
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}
		$image[0] = maybe_swap_to_webp_url( (string) $image[0] );
		return $image;
	},
	10,
	1
);

add_filter(
	'wp_calculate_image_srcset',
	function ( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$sources[ $width ]['url'] = maybe_swap_to_webp_url( (string) $source['url'] );
		}
		return $sources;
	},
	10,
	1
);

// Covers wp_get_attachment_url() callers — most commonly SEO/social plugins building
// og:image / twitter:image / schema.org JSON-LD pointing at the original attachment.
add_filter(
	'wp_get_attachment_url',
	function ( $url ) {
		return is_string( $url ) ? maybe_swap_to_webp_url( $url ) : $url;
	},
	10,
	1
);

// All in One SEO caches the og:image / twitter:image URL in its own wp_aioseo_posts table at
// save time, then renders that string directly — the wp_get_attachment_url path is bypassed.
// AIOSEO emits the tags as a flat assoc array of [meta_name => value]; we rewrite the values
// for the image/url-bearing keys. No-op when AIOSEO is not installed.
$aioseo_meta_rewriter = function ( $tags ) {
	if ( ! is_array( $tags ) ) {
		return $tags;
	}
	foreach ( $tags as $key => $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			continue;
		}
		if ( false === strpos( $value, '://' ) ) {
			continue;
		}
		$tags[ $key ] = maybe_swap_to_webp_url( $value );
	}
	return $tags;
};
add_filter( 'aioseo_facebook_tags', $aioseo_meta_rewriter, 99 );
add_filter( 'aioseo_twitter_tags', $aioseo_meta_rewriter, 99 );

// Covers wp_get_attachment_image() consumers that build the final <img> attribute array
// (src + srcset) themselves rather than going through wp_calculate_image_srcset.
// Final-sweep output buffer. Some plugins (AIOSEO Pro caches og:image in wp_aioseo_posts,
// social-button plugins, JSON-LD schema emitters) bypass every WP filter and write the URL
// straight to the page. A regex pass on the final HTML catches all of them. Skipped for
// admin, AJAX, REST, cron, and feed responses; only raster URLs under /wp-content/uploads
// with an existing .webp sidecar are rewritten, so the worst-case outcome is "no change."
function maybe_start_html_rewriter(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return;
	}
	if ( apply_filters( 'wp_auto_webp_skip_output_buffer', false ) ) {
		return;
	}
	ob_start( __NAMESPACE__ . '\rewrite_html_output' );
}
add_action( 'template_redirect', __NAMESPACE__ . '\maybe_start_html_rewriter', 0 );

function rewrite_html_output( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}
	if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<!doctype' ) ) {
		return $html;
	}

	list( $base_url, $base_dir ) = uploads_base();
	$host_path = ltrim( strip_scheme( $base_url ), '/' ); // host + path, no scheme, no leading //

	// Match upload-base URLs to JPG/PNG/GIF, allowing four common slash encodings:
	//   plain /        — typical HTML attributes
	//   escaped \/     — JSON / JSON-LD blocks
	//   entity &#47;   — over-cautious HTML escapers
	//   unicode / — JSON encoders that escape forward slash to unicode
	// Delimiter is `~` because the pattern contains `#` (in &#47;) and `?` (in the
	// query lookahead); `#` or `/` as the delimiter would terminate the regex early.
	$slash = '(?:\\\\\/|\\\\u002F|&#47;|/)';

	// Build a host-path matcher that accepts any slash encoding between segments,
	// not only the `/` we pulled out of get_upload_dir(). Without this, JSON-LD
	// URLs like "https:\/\/example.com\/wp-content\/uploads\/..." never match.
	$host_segments = array_map(
		function ( $part ) {
			return preg_quote( $part, '~' );
		},
		explode( '/', $host_path )
	);
	$host_pattern = implode( $slash, $host_segments );

	$pattern = '~(?:https?:)?' . $slash . $slash . $host_pattern . $slash . '[^\s"\'<>()]+?\.(?:jpe?g|png|gif)(?=["\'\s<>?#)\\\\]|$)~i';

	$out = preg_replace_callback(
		$pattern,
		function ( $m ) {
			$url = $m[0];
			$normalized = str_replace( array( '\\/', '\\u002F', '&#47;' ), '/', $url );
			$new        = maybe_swap_to_webp_url( $normalized );
			if ( $new === $normalized ) {
				return $url;
			}
			if ( false !== strpos( $url, '\\u002F' ) ) {
				return str_replace( '/', '\\u002F', $new );
			}
			if ( false !== strpos( $url, '\\/' ) ) {
				return str_replace( '/', '\\/', $new );
			}
			if ( false !== strpos( $url, '&#47;' ) ) {
				return str_replace( '/', '&#47;', $new );
			}
			return $new;
		},
		$html
	);

	// preg_replace_callback returns null on regex error. Returning null from an
	// ob_start callback blanks the page, so always fall back to the original HTML.
	return ( null === $out ) ? $html : $out;
}

add_filter(
	'wp_get_attachment_image_attributes',
	function ( $attr ) {
		if ( ! is_array( $attr ) ) {
			return $attr;
		}
		if ( ! empty( $attr['src'] ) ) {
			$attr['src'] = maybe_swap_to_webp_url( (string) $attr['src'] );
		}
		if ( ! empty( $attr['srcset'] ) ) {
			$parts = preg_split( '/\s*,\s*/', (string) $attr['srcset'] );
			$out   = array();
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$tokens     = preg_split( '/\s+/', $part, 2 );
				$swapped    = maybe_swap_to_webp_url( $tokens[0] );
				$descriptor = $tokens[1] ?? '';
				$out[]      = $swapped . ( '' !== $descriptor ? ' ' . $descriptor : '' );
			}
			$attr['srcset'] = implode( ', ', $out );
		}
		return $attr;
	},
	10,
	1
);

/**
 * WP-CLI: `wp auto-webp run --post=ID` (or --all) to (re)process posts on demand.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'auto-webp run',
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
				ensure_featured_image_webp( $post->ID, $stats );
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
					'description' => 'Process every post matching wp_auto_webp_post_types.',
				),
			),
		)
	);
}

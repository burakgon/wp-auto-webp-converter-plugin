=== WP Auto WebP Converter ===
Contributors: burakgon
Tags: webp, images, performance, optimization
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-convert images referenced in post content to WebP on save, keeping originals as fallback.

== Description ==

On every `save_post_post`, the plugin scans the post content for `<img>` references to JPG / PNG / GIF files that live under `/wp-content/uploads`. For each, it generates a sibling `.webp` via `WP_Image_Editor` (Imagick preferred, GD fallback) at quality 82, then rewrites `src`, `srcset`, `data-src`, `data-lazy-src`, `data-srcset`, and Gutenberg block-comment `"url"` JSON metadata in the post HTML to point at the `.webp` twin. The original file is never deleted.

Featured images are covered too: on save the plugin generates `.webp` sidecars for the attachment original and every WordPress-generated thumbnail size, then swaps URLs to the `.webp` versions at render time via the `wp_get_attachment_image_src` and `wp_calculate_image_srcset` filters. This catches featured image displays, galleries, REST API responses, RSS enclosures, Open Graph tags, and schema.org JSON-LD without writing anything new to the database.

The post-content DB update bypasses `wp_update_post` and `kses`; we only rewrite URL substrings, never inject HTML.

== Extending ==

* Filter `wp_auto_webp_post_types` to change which post types are touched (default `['post']`).

== WP-CLI ==

    wp auto-webp run --post=123,456
    wp auto-webp run --all

== Changelog ==

= 1.1.0 =
* Featured image + everything-else coverage. On save, generates `.webp` sidecars for the featured image and every WP-generated thumbnail size. At render time, URLs are swapped to `.webp` via `wp_get_attachment_image_src`, `wp_calculate_image_srcset`, `wp_get_attachment_image_attributes`, `wp_get_attachment_url`, and (when AIOSEO is installed) `aioseo_facebook_tags` / `aioseo_twitter_tags` filters. A final-sweep output buffer on `template_redirect` rewrites any remaining raster URL under `/wp-content/uploads` in the rendered HTML — including JSON-LD blocks — handling plain `/`, JSON `\/`, unicode `/`, and HTML-entity `&#47;` slash encodings. Skippable via `wp_auto_webp_skip_output_buffer` filter.

= 1.0.0 =
* Initial release.

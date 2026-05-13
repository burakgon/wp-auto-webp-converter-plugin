=== WP Auto WebP Converter ===
Contributors: burakgon
Tags: webp, images, performance, optimization
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-convert images referenced in post content to WebP on save, keeping originals as fallback.

== Description ==

On every `save_post_post`, the plugin scans the post content for `<img>` references to JPG / PNG / GIF files that live under `/wp-content/uploads`. For each, it generates a sibling `.webp` via `WP_Image_Editor` (Imagick preferred, GD fallback) at quality 82, then rewrites `src`, `srcset`, `data-src`, `data-lazy-src`, `data-srcset`, and Gutenberg block-comment `"url"` JSON metadata in the post HTML to point at the `.webp` twin. The original file is never deleted.

The DB update bypasses `wp_update_post` and `kses`; we only rewrite URL substrings, never inject HTML.

== Extending ==

* Filter `wp_auto_webp_post_types` to change which post types are touched (default `['post']`).

== WP-CLI ==

    wp auto-webp run --post=123,456
    wp auto-webp run --all

== Changelog ==

= 1.0.0 =
* Initial release.

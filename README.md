# WP Auto WebP Converter

**A WordPress plugin that turns the images in your posts into WebP automatically the moment you hit Save.**

[![WordPress plugin](https://img.shields.io/badge/WordPress-plugin-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WordPress version](https://img.shields.io/badge/WordPress-5.8%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

No setup. No background queue to babysit. No client-side JavaScript. Just install, activate, and every post you save afterwards ships with `.webp` images in the HTML — while the originals stay on disk untouched as a fallback.

---

## Why

Most WebP plugins fall into one of two camps: heavyweight "image optimization suites" that ask for an API key and a credit card, or `.htaccess` rewrites that quietly serve `.webp` to browsers behind the scenes. Both have their place. This one solves a smaller, sharper problem:

> When I publish a post, I want the rendered HTML to literally contain `<img src="…/photo.webp">`, not `…/photo.jpg`.

That way every downstream consumer — Cloudflare, your CDN, AMP, RSS aggregators, Open Graph crawlers, RSS-to-newsletter pipelines — gets the WebP URL directly, no `Accept` header negotiation, no `<picture>` element gymnastics.

## What it does

On every `save_post_post` (manual draft save, publish, scheduled, REST API, classic editor, block editor — anything that fires the hook), the plugin will:

1. Read the saved post content and find every `<img>`-style URL pointing to a JPG / PNG / GIF inside `/wp-content/uploads`.
2. Generate a sibling `.webp` next to each original. Photos use WebP quality 95; PNG files use lossless WebP so text, UI graphics, and transparency remain pixel-accurate. Imagick is preferred, with the WordPress image editor as fallback.
3. Rewrite `src`, `srcset`, `data-src`, `data-lazy-src`, and `data-srcset` attributes — plus Gutenberg block-comment `"url":"…"` metadata — to point at the `.webp` twin.
4. Write the updated HTML straight back to the `wp_posts` table.
5. Generate `.webp` sidecars for the **featured image** attachment original and every WP-generated thumbnail size.
6. At render time, transparently swap featured image, gallery, REST API, schema.org, and Open Graph URLs to their `.webp` versions via the `wp_get_attachment_image_src` and `wp_calculate_image_srcset` filters whenever a sidecar is present on disk.

Originals are never deleted. External URLs and non-raster files (SVG, JS, etc.) are skipped. Already-converted posts are a no-op on resave.

## What it does **not** do

- Does not generate AVIF (the underlying libraries support it; a small fork away if you want it).
- Does not bulk-process the entire media library automatically. The opt-in `wp auto-webp regenerate` command can upgrade it in disk-safe batches.
- Does not purge your CDN. If you sit behind Cloudflare / Bunny / Fastly, your edge cache will keep serving the old HTML until it expires. Wire your own purge into the `wp_auto_webp_post_types` flow if you need instant invalidation.

## Installation

### Via WP-CLI

```bash
wp plugin install https://github.com/burakgon/wp-auto-webp-converter-plugin/archive/refs/heads/main.zip --activate
```

### Manual

1. Download or clone this repository.
2. Copy the `wp-auto-webp-converter-plugin` directory into `wp-content/plugins/`.
3. Activate **WP Auto WebP Converter** from the WordPress **Plugins** screen.

### Requirements

- WordPress 5.8+
- PHP 7.4+
- One of:
  - PHP **Imagick** extension built with WebP support (recommended), or
  - PHP **GD** extension built with WebP support (`gd_info()['WebP Support']` should be true).

Most modern hosts ship both. If you are unsure, save a post with an image and check the post meta `_wp_auto_webp_stats` — `converted > 0` confirms the encoder is wired up.

## Usage

There is nothing to configure. Save a post; check the rendered HTML.

A short, deliberate example:

```html
<!-- Before save -->
<figure class="wp-block-image">
  <img src="https://example.com/wp-content/uploads/2026/01/cover.jpg"
       srcset="https://example.com/wp-content/uploads/2026/01/cover-300x200.jpg 300w,
               https://example.com/wp-content/uploads/2026/01/cover-768x512.jpg 768w" />
</figure>

<!-- After save -->
<figure class="wp-block-image">
  <img src="https://example.com/wp-content/uploads/2026/01/cover.webp"
       srcset="https://example.com/wp-content/uploads/2026/01/cover-300x200.webp 300w,
               https://example.com/wp-content/uploads/2026/01/cover-768x512.webp 768w" />
</figure>
```

The corresponding `cover.webp`, `cover-300x200.webp`, and `cover-768x512.webp` files now exist on disk alongside the JPGs.

## Backfilling existing posts (WP-CLI)

After activating, you almost certainly want to run the conversion on everything you already published:

```bash
# Convert a single post
wp auto-webp run --post=1234

# Convert several posts
wp auto-webp run --post=1234,5678,9012

# Convert every post matching wp_auto_webp_post_types (default: 'post')
wp auto-webp run --all
```

The CLI command prints per-post counts and a final summary:

```
post 1234: converted=3 failed=0 skipped=1 (content updated)
post 5678: converted=0 failed=0 skipped=0 (no change)
Success: Done. posts_changed=12 converted=47 failed=0 skipped=8
```

`skipped` counts external image URLs (URLs not under `/wp-content/uploads`). `failed` counts images the editor could not open — usually deleted source files or unreadable permissions.

### Re-encoding existing WebP sidecars

Version 1.2 fixes an Imagick ordering issue that could make the configured WebP quality ineffective. New and re-saved images automatically use the corrected encoder. Existing media can be upgraded explicitly:

```bash
# Re-encode one source image (path is relative to wp-content/uploads)
wp auto-webp regenerate --file=2026/01/cover.jpg

# Preview how many old sidecars remain
wp auto-webp regenerate --all --dry-run

# Upgrade in repeatable batches; already-upgraded files are skipped on the next run
wp auto-webp regenerate --all --limit=1000
```

The command writes to a temporary file and atomically replaces the public `.webp` only after a successful encode. A 5 GiB free-space guard stops bulk work before the uploads disk becomes dangerously full.

## Extending

### Process additional post types

```php
add_filter( 'wp_auto_webp_post_types', function ( $types ) {
    $types[] = 'page';
    $types[] = 'product';
    return $types;
} );
```

### Adjust photo quality or PNG lossless mode

```php
add_filter( 'wp_auto_webp_quality', function ( $quality, $source_path, $mime ) {
    return 95; // 1-100; used for lossy images.
}, 10, 3 );

add_filter( 'wp_auto_webp_lossless', function ( $lossless, $source_path, $mime ) {
    return $lossless; // Defaults to true for PNG and false for JPEG.
}, 10, 3 );
```

### Read what happened on the last save

Two post-meta keys are written every time the hook runs (whether or not content changed):

| Meta key | Value |
|---|---|
| `_wp_auto_webp_last_run` | Unix timestamp of the most recent run |
| `_wp_auto_webp_stats` | `['converted' => N, 'failed' => N, 'skipped_external' => N]` |

```php
$stats = get_post_meta( $post_id, '_wp_auto_webp_stats', true );
```

## How it works (under the hood)

- **Hook:** `save_post_post` at priority 20. Autosaves, revisions, `auto-draft`, `trash`, and `inherit` statuses are rejected up front so the heavy work never runs for noise saves.
- **Encoder:** With Imagick, the output format is switched to WebP *before* WebP options are applied. JPEG uses quality 95 and method 6. PNG uses lossless mode with alpha quality 100. ICC colour profiles are retained while EXIF and other non-rendering metadata are removed. The WordPress image editor remains the compatibility fallback.
- **Safe writes:** Encoding happens in a same-directory temporary file. The public sidecar is atomically replaced only after a complete, non-empty WebP has been produced.
- **Idempotency:** The plugin compares source/sidecar timestamps and the encoder release timestamp. Current sidecars are reused; older v1.0/v1.1 sidecars are upgraded the next time that source is reached or through `wp auto-webp regenerate`.
- **DB writes:** Content updates go through `$wpdb->update` directly, not `wp_update_post`. This is deliberate: we only rewrite URL substrings inside attributes we found, never inject new HTML, so we skip `wp_kses` re-sanitization and we don't re-enter the `save_post` action.
- **Block editor compatibility:** Gutenberg stores image URLs both in the inner `<img src>` *and* in the block-comment JSON header (`<!-- wp:image {"url":"…"} -->`). Both are rewritten in lockstep, so the editor will not flag a "block recovery" mismatch on reopen.

## FAQ

**Will it break my image lightbox / responsive images / lazy-load plugin?**
It only changes attribute *values*, never tag structure. If your lightbox plugin reads `src` and `data-src` (the standard pair), it will keep working — the URL just ends in `.webp` now.

**Does this delete the original JPGs/PNGs?**
No. They remain on disk so any external link, RSS reader, OG image consumer, or old cache that still references them gets a 200, not a 404.

**Will my old posts get converted?**
Not until you re-save them, or run `wp auto-webp run --all` once.

**Why not use a `<picture>` element with `<source type="image/webp">`?**
You can, and it's a fine approach. But rewriting `src` directly is simpler, plays better with downstream consumers (RSS, OG tags, AMP), and works with every theme/block out of the box. WebP browser support is universal as of 2024 — the fallback path is rarely exercised in practice.

**What happens if the same source has both a `.webp` from me and one from another plugin?**
The newest one wins per `filemtime` check — usually that's whichever plugin ran most recently. There is only ever one `.webp` per source.

**Can I change the quality?**
Yes. Use the `wp_auto_webp_quality` filter; the default is 95. PNG is lossless by default and can be changed with `wp_auto_webp_lossless`.

## Changelog

### 1.2.1
- Rejects lookalike hosts such as `example.com.evil` and sibling paths such as `uploads-archive` when mapping upload URLs to local files.
- Adds automated URL-boundary regression tests across supported PHP versions.

### 1.2.0
- Fixes the Imagick format/quality ordering bug that could make the configured WebP quality ineffective.
- Raises lossy photo quality to 95 and encodes PNG as lossless WebP by default.
- Preserves ICC colour profiles while removing non-rendering metadata.
- Uses atomic temporary-file writes so a failed encode cannot replace a working public image.
- Automatically upgrades old sidecars when their source is reached.
- Adds `wp auto-webp regenerate --file=...` and disk-safe, repeatable `--all --limit=...` batches, plus `--dry-run`.
- Adds `wp_auto_webp_quality`, `wp_auto_webp_lossless`, and `wp_auto_webp_min_free_disk_bytes` filters.

### 1.1.0
- **Featured image + everything-else coverage.** On save, `.webp` sidecars are generated for the featured image attachment and every WordPress-generated thumbnail size. At render time URLs are swapped to `.webp` via a stack of complementary filters:
  - `wp_get_attachment_image_src`, `wp_calculate_image_srcset`, `wp_get_attachment_image_attributes`, `wp_get_attachment_url` — standard WP rendering paths.
  - `aioseo_facebook_tags`, `aioseo_twitter_tags` — All in One SEO's cached og/twitter image URLs.
  - A final-sweep output buffer on `template_redirect` rewrites any remaining raster URL under `/wp-content/uploads` in the rendered HTML head/body — including JSON-LD `<script type="application/ld+json">` blocks. Handles plain `/`, JSON-escaped `\/`, JSON-unicode `/`, and HTML-entity `&#47;` slash encodings, and re-encodes them the same way it found them so JSON-LD doesn't break.
- Output buffer is skipped for admin, AJAX, REST, cron, and feed responses. Filter `wp_auto_webp_skip_output_buffer` available to disable it explicitly.
- `wp auto-webp run --post|--all` now also generates featured image sidecars during backfill.

### 1.0.0
- Initial release.
- Rewrites `src`, `srcset`, `data-src`, `data-lazy-src`, `data-srcset`, and block-comment JSON `"url"` metadata.
- WP-CLI: `wp auto-webp run --post=ID[,ID]` and `wp auto-webp run --all`.
- Filters: `wp_auto_webp_post_types`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

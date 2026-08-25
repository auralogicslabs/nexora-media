=== Nexora Media – Image Optimization ===
Contributors: auralogics
Tags: webp, image optimization, lazy load, performance, media
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress image optimization with WebP variants, a safe background queue, adaptive frontend delivery, and a queue health system that explains itself.

== Description ==

**Nexora Media is the intelligent image optimization plugin for WordPress.** It generates WebP variants of every image you upload, swaps them in automatically for public visitors, and keeps your editors and page builders untouched.

**Safe by default.** Logged-in editors, Elementor previews, Divi builder, Bricks, Oxygen, customizer, and any builder preview frame always see the original image. Nothing breaks while you build.

**Background optimization queue.** Heavy variant generation runs in safe batches so uploads stay snappy and WordPress stays responsive. Manual mode is the default — automatic processing is an explicit opt-in.

**Adaptive frontend delivery.** When enabled, eligible WordPress image URLs are seamlessly replaced with the optimized WebP for logged-out visitors. The original file stays in place and is served as a fallback whenever needed.

**Queue health that talks back.** When something goes wrong — a stale lock, a permission error, a memory issue — Nexora Media surfaces a clear alert with a recovery action. No more silent failures, no more "the queue stopped and I don't know why".

= Why "safe" matters =

Most image optimizers break something. A bad src rewrite turns a working lightbox into a blank popup. An aggressive cache strategy serves stale images on cached pages. A naive lazy-load tag kills above-the-fold paint.

Nexora Media is conservative on purpose. It:

* Detects every major page builder and bypasses delivery rewriting during editing
* Skips lightboxes, galleries, carousels, logos, menus, and any image marked `data-no-lazy`, `data-no-webp`, or `fetchpriority=high`
* Only swaps URLs when the WebP variant is actually smaller than the original
* Never deletes your originals
* Provides a one-click "Recover stuck queue" action if anything ever stalls

= Features =

* **WebP generation** — Imagick preferred, GD fallback
* **Background optimization queue** with safe batching and worker locking
* **Adaptive frontend delivery** that swaps WordPress image URLs to WebP for public visitors
* **Responsive image variants** (320, 640, 960, 1600 by default, configurable)
* **Lazy loading + decoding=async** with intelligent hero-image guards
* **EXIF stripping** for privacy and smaller files
* **Per-image controls** to force original delivery on a single image when needed
* **Queue health dashboard** with structured error logs, stale-lock detection, and one-click recovery
* **WP-CLI compatible cron** for automated processing
* **Modern React-based admin** with live queue progress and per-image cards

= Pro extensions =

There aren't any. Nexora Media is fully free, GPL, and complete. New features ship to everyone.

= On the Roadmap =

* One-click CDN cache purge for Cloudflare, BunnyCDN, and other pull-zone CDNs after a variant is regenerated
* Optional WebP/AVIF for theme and plugin CSS background images
* Bulk re-optimization with a configurable quality target

Roadmap items are shown in the app's Roadmap page marked "Coming Soon" and are not yet active.

= Plays well with =

* Elementor (free + Pro)
* Divi
* Bricks
* Oxygen
* WPBakery
* Beaver Builder
* Yoast SEO, Rank Math, AIO SEO
* WP Rocket, W3 Total Cache, LiteSpeed Cache, Cloudflare
* WooCommerce
* **Nexora Engine** (sibling plugin) — when active, Engine handles inline-CSS image rewriting during static site generation so Media stands down on `the_content` rewriting to avoid double-processing

== Installation ==

1. Upload the `nexora-media` folder to `/wp-content/plugins/`, or install through the WordPress Plugins screen.
2. Activate Nexora Media from the Plugins screen.
3. Open Nexora Media in the admin menu. The setup wizard will walk you through the recommended safe settings.
4. Run "Optimize all images" to process your existing library, or let new uploads queue automatically.

== Frequently Asked Questions ==

= Does Nexora Media process images during upload? =

By default, no — uploads are queued and processed in small background batches to avoid PHP timeouts and memory pressure. You can enable upload-time processing in Settings if your server is generous with resources.

= Will it break my Elementor / Divi / page builder site? =

No. Nexora Media detects every major builder's preview frame and never rewrites image URLs during editing. Public visitors get the optimized variant; logged-in editors always see the original.

= What happens to my existing images? =

Nothing is changed about the original. Nexora Media generates a `.webp` sidecar next to each original (e.g. `photo.jpg` stays where it is, and a new `photo.webp` is added). If you ever uninstall the plugin, your originals remain untouched.

= Does it work with CDNs? =

Yes — Cloudflare, BunnyCDN, KeyCDN and any pull-zone CDN serve the WebP variants automatically once your site references them. Cache invalidation hooks for one-click CDN purging are on the roadmap.

= What if a single image keeps failing? =

After three consecutive failures, that image is automatically given a 24-hour cooldown so it doesn't block the rest of your queue. You'll see the error in the Diagnostic page so you can investigate.

= How does it work with Nexora Engine? =

If you have Nexora Engine installed and SSG is enabled, Engine handles inline-CSS image rewriting during static site generation. Media generates the variants and emits a clean `nxm_variant_resolved` signal that Engine consumes. You don't need to configure anything — the integration is automatic.

= Does it call home or send any data anywhere? =

No. Nexora Media is fully self-contained. It does not make any external HTTP requests.

= How do I uninstall cleanly? =

Deactivate and delete the plugin from the Plugins screen. Nexora Media removes all of its options, transients, scheduled events, user meta flags, and the generated CSS cache directory on uninstall. Generated `.webp` variants next to your images are preserved on purpose — you may still be referencing them from a CDN cache or a static site mirror.

== Screenshots ==

1. Dashboard — library stats, space saved, optimization pipeline, and Nexora Engine connection at a glance
2. Media Library — per-image cards with status, savings, and a one-click "use original" delivery toggle
3. Frontend Delivery — safe vs advanced settings clearly separated, with builder-safe defaults
4. Engine Bridge — automatic Nexora Engine SSG integration so static mirrors stay in sync
5. Roadmap — what we're building next and what we're researching, all in the open
6. Diagnostic — server capabilities, queue health, and the structured error log

== Source code & development ==

Nexora Media is fully open. The admin interface is a React + TypeScript application; the human-readable source lives in the `frontend/` directory that ships inside the plugin, and it is compiled with Vite to `assets/dist/nexora-media.js`. Nothing is obfuscated or minified beyond a standard production build.

The complete, unminified source — including the React/TypeScript admin UI and build instructions — is publicly available at:

https://github.com/auralogicslabs/nexora-media

To reproduce the compiled bundle from source (see BUILD.md in the repository for full details):

`npm install`
`npm run build`

This runs Vite (`vite.config.ts`) over the `frontend/` sources and writes the bundle to `assets/dist/`. Node.js 18+ and npm 9+ are required. The plugin runs entirely from the built output at runtime; `node_modules/` is a development dependency only and is not shipped.

== Changelog ==

= 1.0.0 =
First public release of Nexora Media.
* WebP and AVIF variant generation — Imagick preferred, GD fallback.
* Background optimization queue with safe batching, worker locking, and per-image failure cooldown.
* Adaptive frontend delivery — eligible image URLs are swapped to the optimized variant for logged-out visitors only; the original is always kept and served as a fallback.
* Builder-safe: Elementor, Divi, Bricks, Oxygen, WPBakery, Beaver Builder, and the customizer always see the original image while editing.
* Responsive variants (configurable sizes), lazy loading with intelligent hero-image guards, and EXIF stripping.
* Queue health system — structured error log, stale-lock detection, cron health check, and one-click recovery.
* Per-image controls to force original delivery on a single image.
* Modern React-based admin with live queue progress and per-image cards.
* WP-CLI compatible background processing.
* Nexora Engine bridge — stands down on inline-CSS image rewriting when Engine handles it during static site generation.
* Proper uninstall.php cleans up options, transients, scheduled events, and user meta on deletion (generated .webp variants are preserved on purpose).

== Upgrade Notice ==

= 1.0.0 =
First public release. Run "Optimize all images" after activation to process your existing library, or let new uploads queue automatically.

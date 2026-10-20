=== Milyutin Cache Optimizer (Lite) ===
Contributors: milyutinkv
Tags: cache, performance, webp, lazy load, speed
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, fast, and safe WordPress performance plugin. Includes page caching, HTML minification, Lazy Load, and WebP conversion.

== Description ==

**Milyutin Cache Optimizer** is a simple yet powerful plugin to speed up your WordPress site.

*   **Page Caching:** Ultra-fast file-based HTML caching.
*   **HTML Minification:** Removes comments and whitespace safely.
*   **WebP & Lazy Load:** Converts images on upload and adds native lazy loading.
*   **Critical CSS:** Inlines the first CSS file to improve FCP.

Upgrade to the **Pro Version** (available on the author's website) to unlock Redis Object Cache, Cloudflare CDN integration, Cache Preload, and Database Cleanup.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to "Milyutin Cache" in the admin menu to configure the plugin.

== Frequently Asked Questions ==

= Does this plugin modify my original images? =
No. WebP conversion creates a new `.webp` file next to the original.

= Will HTML minification break my inline scripts? =
No. The plugin protects `<pre>`, `<textarea>`, `<script>`, and `<style>` blocks from being modified.

== Changelog ==

= 1.1.1 =
* Initial release for WordPress.org

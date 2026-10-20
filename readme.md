# Milyutin Cache Optimizer (Lite)

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![Version](https://img.shields.io/badge/version-1.1.1-green)](https://github.com/milyutinkv-coder/milyutin-cache-optimizer/releases)

> A lightweight, fast, and safe WordPress performance plugin. It includes file-based page caching, HTML minification, Lazy Load, WebP conversion, and Critical CSS generation.

**Author:** Konstantin Milyutin · [milyutin-codes.ru](https://milyutin-codes.ru) · [VK Group](https://vk.com/milyutin_codes)

---

## ✨ Features (Free Version)

- ⚡ **Page Caching** — Ultra-fast file-based HTML caching with mobile and logged-in user support.
- 🧹 **HTML Minification** — Safely removes comments and extra whitespace without breaking inline tags or `<pre>` blocks.
- 🖼️ **WebP & Lazy Load** — Converts images on upload, serves them via `<picture>`, and defers off-screen images using native `loading="lazy"`.
- 🔤 **JS Optimization** — Adds `defer` or delays external scripts until user interaction (optional).
- 🚀 **Critical CSS** — Inlines the first CSS file to improve First Contentful Paint (FCP).

## 🔒 Pro Version Available!

Looking for maximum performance? Upgrade to **Milyutin Cache Optimizer Pro** to unlock:

- ⚡ **Persistent Object Cache** (Redis, Memcached) — reduces database load by up to 90%.
- ☁️ **Cloudflare CDN Integration** — automatically purges CDN cache when local cache is cleared.
- 🔄 **Cache Preload** — automatically warms up the cache after publishing or updating posts.
- 🗑️ **Smart Database Cleanup** — scheduled removal of old revisions, expired transients, and spam comments.

👉 **[Buy Pro Version on milyutin-codes.ru](https://milyutin-codes.ru)**

## 📋 Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- For WebP: Imagick or GD with WebP support

## 🚀 Installation

1. Upload the `milyutin-cache-optimizer` folder to `wp-content/plugins/` (or upload a ZIP file via **Plugins → Add New → Upload Plugin**).
2. Activate the plugin.
3. Go to **Milyutin Cache** in the admin menu to configure your settings.

## 📂 File Structure

```text
milyutin-cache-optimizer/
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── class-mco-cache.php
│   ├── class-mco-critical-css.php
│   ├── class-mco-lazyload.php
│   ├── class-mco-minify.php
│   ├── class-mco-settings.php
│   └── class-mco-webp.php
├── milyutin-cache-optimizer.php
└── uninstall.php
```
## ❓ FAQ

| Question | Answer |
|---|---|
| **Does this plugin modify my original images?** | No. WebP conversion creates a new `.webp` file next to the original. |
| **Will HTML minification break my inline scripts?** | No. The plugin protects `<pre>`, `<textarea>`, `<script>`, and `<style>` blocks from being modified. |
| **How do I get Redis support?** | Redis and Memcached support are available in the **Pro version**. |

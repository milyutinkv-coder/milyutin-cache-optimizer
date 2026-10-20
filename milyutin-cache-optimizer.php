<?php
/**
 * Plugin Name:       Milyutin Cache Optimizer (Lite)
 * Plugin URI:        https://github.com/milyutinkv-coder/milyutin-cache-optimizer-lite
 * Description:       Кэш страниц, безопасная минификация HTML, Lazy Load, WebP и инлайн основного CSS. (Pro-версия доступна на сайте автора).
 * Version:           1.1.1
 * Author:            Константин Милютин
 * Author URI:        https://milyutin-codes.ru
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       milyutin-cache-optimizer-lite
 * Requires at least: 6.0
 * Requires PHP:      8.0
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCO_VERSION', '1.1.1' );
define( 'MCO_PLUGIN_FILE', __FILE__ );
define( 'MCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MCO_CACHE_DIR', WP_CONTENT_DIR . '/cache/milyutin-cache' );
define( 'MCO_CACHE_URL', WP_CONTENT_URL . '/cache/milyutin-cache' );
define( 'MCO_OPTION_KEY', 'mco_settings' );
define( 'MCO_MARKER', 'Milyutin Cache Optimizer' );

spl_autoload_register( 'mco_autoload' );

function mco_autoload( $class ) {
	if ( 0 !== strpos( (string) $class, 'MCO_' ) ) {
		return;
	}
	$file = MCO_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

function mco_default_settings(): array {
	return [
		'enabled'               => 1,
		'cache_mobile'          => 1,
		'cache_logged_in'       => 0,
		'cache_timeout'         => 3600,
		'cache_query_ignore'    => "utm_source\nutm_medium\nutm_campaign\nutm_term\nutm_content\nutm_id\nfbclid\ngclid\nyclid\nmsclkid\nmc_cid\nmc_eid",
		'exclude_urls'          => "wp-admin\nwp-login.php\ncart\ncheckout\nmy-account",
		'minify_html'           => 1,
		'defer_js'              => 0,
		'defer_js_exclude'      => "jquery\njquery-migrate\nwp-includes/js/dist",
		'delay_js'              => 0,
		'delay_js_exclude'      => "jquery\ncontact-form-7",
		'lazyload_images'       => 1,
		'lazyload_iframes'      => 1,
		'lazyload_skip_first'   => 2,
		'webp_enabled'          => 1,
		'webp_quality'          => 82,
		'critical_css_enabled'  => 0,
		'critical_css_limit'    => 15000,
		'gzip_enabled'          => 1,
		'browser_cache'         => 1,
	];
}

function mco_get_settings(): array {
	$saved = get_option( MCO_OPTION_KEY, [] );
	return array_merge( mco_default_settings(), is_array( $saved ) ? $saved : [] );
}

function mco_lines( $value ): array {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
	$out   = [];
	foreach ( (array) $lines as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

function mco_preg( string $pattern, callable $callback, string $subject, int $limit = -1 ): string {
	$result = @preg_replace_callback( $pattern, $callback, $subject, $limit );
	return is_string( $result ) ? $result : $subject;
}

function mco_protect( string $html, string $pattern, array &$store ): string {
	return mco_preg(
		$pattern,
		static function ( $matches ) use ( &$store ) {
			$key           = "\x02MCO" . count( $store ) . "\x03";
			$store[ $key ] = $matches[0];
			return $key;
		},
		$html
	);
}

function mco_restore( string $html, array $store ): string {
	return $store ? strtr( $html, $store ) : $html;
}

add_action( 'plugins_loaded', 'mco_init', 5 );
add_action( 'init', 'mco_load_textdomain' );

function mco_load_textdomain() {
	load_plugin_textdomain( 'milyutin-cache-optimizer-lite', false, dirname( plugin_basename( MCO_PLUGIN_FILE ) ) . '/languages' );
}

function mco_init() {
	$opts = mco_get_settings();
	if ( is_admin() ) {
		new MCO_Settings();
		new MCO_Site_Health();
	}
	new MCO_Cache();
	$active = ! empty( $opts['enabled'] );
	if ( $active && ! is_admin() ) {
		if ( ! empty( $opts['minify_html'] ) || ! empty( $opts['defer_js'] ) || ! empty( $opts['delay_js'] ) ) {
			new MCO_Minify();
		}
		if ( ! empty( $opts['lazyload_images'] ) || ! empty( $opts['lazyload_iframes'] ) ) {
			new MCO_LazyLoad();
		}
		if ( ! empty( $opts['webp_enabled'] ) ) {
			new MCO_WebP();
		}
		if ( ! empty( $opts['critical_css_enabled'] ) ) {
			new MCO_Critical_CSS();
		}
	}
	if ( $active && ! empty( $opts['webp_enabled'] ) ) {
		MCO_WebP::register_upload_hooks();
	}
}

register_activation_hook( __FILE__, 'mco_activate' );
register_deactivation_hook( __FILE__, 'mco_deactivate' );

function mco_activate() {
	MCO_Cache::install_protection_files();
	if ( ! get_option( MCO_OPTION_KEY ) ) {
		update_option( MCO_OPTION_KEY, mco_default_settings() );
	}
	MCO_Cache::update_htaccess_rules();
}

function mco_deactivate() {
	wp_clear_scheduled_hook( 'mco_cleanup_expired_cache' );
	MCO_Cache::remove_htaccess_rules();
}
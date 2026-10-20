<?php
/**
 * Удаление плагина.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mco_marker     = 'Milyutin Cache Optimizer';
$mco_cache_dir  = WP_CONTENT_DIR . '/cache/milyutin-cache';
$mco_dropin     = WP_CONTENT_DIR . '/object-cache.php';
$mco_htaccess   = ABSPATH . '.htaccess';

$mco_cleanup_site = static function () {
	delete_option( 'mco_settings' );
	delete_option( 'mco_preload_queue' );

	wp_clear_scheduled_hook( 'mco_cleanup_expired_cache' );
	wp_clear_scheduled_hook( 'mco_db_cleanup_cron' );
	wp_clear_scheduled_hook( 'mco_preload_batch' );
	wp_clear_scheduled_hook( 'mco_preload_batch_single' );

	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_mco_webp_done' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
};

if ( is_multisite() ) {
	$mco_sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( (array) $mco_sites as $mco_site_id ) {
		switch_to_blog( (int) $mco_site_id );
		$mco_cleanup_site();
		restore_current_blog();
	}
} else {
	$mco_cleanup_site();
}

if ( file_exists( $mco_dropin ) ) {
	$mco_head = (string) @file_get_contents( $mco_dropin, false, null, 0, 2048 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== strpos( $mco_head, $mco_marker ) ) {
		wp_delete_file( $mco_dropin );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
if ( file_exists( $mco_htaccess ) && is_writable( $mco_htaccess ) ) {
	$mco_content = (string) @file_get_contents( $mco_htaccess ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$mco_begin   = '# BEGIN ' . $mco_marker;
	$mco_end     = '# END ' . $mco_marker;
	if ( false !== strpos( $mco_content, $mco_begin ) && false !== strpos( $mco_content, $mco_end ) ) {
		$mco_pattern = '/[ \t]*' . preg_quote( $mco_begin, '/' ) . '.*?' . preg_quote( $mco_end, '/' ) . '[ \t]*\r?\n?/s';
		$mco_content = preg_replace( $mco_pattern, '', $mco_content, 1 );
		if ( is_string( $mco_content ) ) {
			@file_put_contents( $mco_htaccess, $mco_content, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}

if ( is_dir( $mco_cache_dir ) ) {
	try {
		$mco_iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $mco_cache_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $mco_iterator as $mco_file ) {
			if ( $mco_file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $mco_file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				wp_delete_file( $mco_file->getPathname() );
			}
		}
	} catch ( UnexpectedValueException $e ) {
		// Ничего не делаем.
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $mco_cache_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
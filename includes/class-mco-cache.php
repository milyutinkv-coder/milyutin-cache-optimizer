<?php
/**
 * Кэш страниц: отдача готового HTML, буферизация вывода, инвалидация, .htaccess.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCO_Cache {
	private array $opts;
	private bool $is_mobile = false;
	private ?int $buffer_level = null;
	private bool $stored = false;

	public function __construct() {
		$this->opts = mco_get_settings();

		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 1 );
		add_action( 'deleted_post', [ $this, 'on_post_change' ] );
		add_action( 'comment_post', [ $this, 'on_post_change' ] );
		add_action( 'edit_comment', [ $this, 'on_post_change' ] );
		add_action( 'wp_set_comment_status', [ $this, 'on_post_change' ] );
		add_action( 'edited_terms', [ $this, 'on_post_change' ] );
		add_action( 'switch_theme', [ $this, 'on_post_change' ] );
		add_action( 'customize_save_after', [ $this, 'on_post_change' ] );
		add_action( 'wp_update_nav_menu', [ $this, 'on_post_change' ] );
		add_action( 'mco_cleanup_expired_cache', [ $this, 'cleanup_expired' ] );

		if ( empty( $this->opts['enabled'] ) || is_admin() ) {
			return;
		}

		add_action( 'template_redirect', [ $this, 'maybe_serve_cache' ], 0 );
		add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 1 );
	}

	public function maybe_serve_cache() {
		if ( ! $this->should_cache() ) {
			return;
		}
		$this->is_mobile = $this->detect_mobile();
		$file = $this->cache_path();
		if ( ! is_readable( $file ) ) {
			return;
		}
		$ttl = (int) $this->opts['cache_timeout'];
		if ( $ttl > 0 && ( time() - (int) filemtime( $file ) ) > $ttl ) {
			wp_delete_file( $file );
			return;
		}
		$etag  = self::file_etag( $file );
		$since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
		$match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		if ( ( '' !== $match && $match === $etag ) || ( $since && $since >= (int) filemtime( $file ) ) ) {
			$this->send_cache_headers( $file );
			status_header( 304 );
			exit;
		}
		$this->send_cache_headers( $file );
		$this->count_hit();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		exit;
	}

	public function maybe_start_buffer() {
		if ( $this->stored || ! $this->should_cache() ) {
			return;
		}
		$level = ob_get_level();
		if ( false === ob_start( [ $this, 'save_buffer' ] ) || ob_get_level() === $level ) {
			return;
		}
		$this->is_mobile    = $this->detect_mobile();
		$this->buffer_level = $level;
		add_action( 'shutdown', [ $this, 'flush_buffer' ], 0 );
	}

	public function save_buffer( $html ) {
		if ( ! is_string( $html ) || '' === $html || $this->stored ) {
			return $html;
		}
		if ( strlen( $html ) < 512 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		if ( is_user_logged_in() && empty( $this->opts['cache_logged_in'] ) ) {
			return $html;
		}
		if ( (int) http_response_code() >= 400 ) {
			return $html;
		}
		$filtered = apply_filters( 'mco_process_html', $html );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			$html = $filtered;
		}
		$this->store( $html );
		return $html;
	}

	public function flush_buffer() {
		if ( null === $this->buffer_level ) {
			return;
		}
		$target = $this->buffer_level;
		$guard  = 0;
		while ( ob_get_level() > $target + 1 && $guard < 10 ) {
			@ob_end_flush();
			++$guard;
		}
		if ( ob_get_level() === $target + 1 ) {
			@ob_end_flush();
		}
		$this->buffer_level = null;
	}

	private function store( string $html ) {
		$file = $this->cache_path();
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$marker = "\n<!-- " . MCO_MARKER . ' | ' . gmdate( 'c' ) . " -->\n";
		if ( false === @file_put_contents( $file, $html . $marker, LOCK_EX ) ) {
			return;
		}
		$this->stored = true;
		$this->send_cache_headers( $file, 'MISS' );
	}

	private function send_cache_headers( string $file, string $status = 'HIT' ) {
		if ( headers_sent() ) {
			return;
		}
		$ttl   = max( 60, (int) $this->opts['cache_timeout'] );
		$mtime = (int) ( @filemtime( $file ) ?: time() );
		header( 'Cache-Control: public, max-age=' . $ttl . ', s-maxage=' . $ttl );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT' );
		header( 'ETag: ' . self::file_etag( $file ) );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'X-MCO-Cache: ' . ( 'MISS' === $status ? 'MISS' : 'HIT' ) );
	}

	private static function file_etag( string $file ): string {
		$mtime = (int) ( @filemtime( $file ) ?: 0 );
		$size  = (int) ( @filesize( $file ) ?: 0 );
		return '"' . dechex( $mtime ) . '-' . dechex( $size ) . '"';
	}

	private function detect_mobile(): bool {
		if ( empty( $this->opts['cache_mobile'] ) ) {
			return false;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return (bool) preg_match( '/(Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile)/i', $ua );
	}

	private function should_cache(): bool {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( is_user_logged_in() && empty( $this->opts['cache_logged_in'] ) ) {
			return false;
		}
		if ( is_feed() || is_trackback() || is_robots() || is_404() || is_search() || is_preview() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_sitemap' ) && is_sitemap() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return false;
		}
		$private_cookies = [ 'wp_woocommerce_session_', 'wp-postpass_', 'comment_author_' ];
		foreach ( array_keys( (array) $_COOKIE ) as $cookie ) {
			$cookie = (string) $cookie;
			foreach ( $private_cookies as $prefix ) {
				if ( 0 === strpos( $cookie, $prefix ) ) {
					return false;
				}
			}
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		$ignore = $this->ignore_args();
		foreach ( array_keys( (array) $_GET ) as $key ) {
			if ( ! in_array( (string) $key, $ignore, true ) ) {
				return false;
			}
		}
		$path = $this->request_path();
		foreach ( mco_lines( $this->opts['exclude_urls'] ?? '' ) as $needle ) {
			if ( '*' === substr( $needle, 0, 1 ) ) {
				if ( false !== strpos( $path, substr( $needle, 1 ) ) ) {
					return false;
				}
			} elseif ( false !== strpos( $path, $needle ) ) {
				return false;
			}
		}
		return true;
	}

	private function ignore_args(): array {
		return mco_lines( $this->opts['cache_query_ignore'] ?? '' );
	}

	private function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '/' . ltrim( $path, '/' );
	}

	private function normalized_query(): string {
		$ignore = $this->ignore_args();
		$args   = [];
		foreach ( (array) $_GET as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, $ignore, true ) ) {
				continue;
			}
			$args[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		ksort( $args );
		return http_build_query( $args );
	}

	private function cache_key(): string {
		$host   = strtolower( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'localhost' );
		$scheme = is_ssl() ? 'https' : 'http';
		$extra  = $this->is_mobile ? '|mob' : '';
		if ( ! empty( $this->opts['cache_logged_in'] ) && is_user_logged_in() ) {
			$cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) $_COOKIE[ LOGGED_IN_COOKIE ] : '';
			$extra .= '|u' . get_current_user_id() . '|' . substr( md5( $cookie ), 0, 8 );
		}
		return md5( $scheme . '://' . $host . $this->request_path() . '?' . $this->normalized_query() . $extra );
	}

	private function cache_path(): string {
		$key = $this->cache_key();
		return MCO_CACHE_DIR . '/' . substr( $key, 0, 2 ) . '/' . substr( $key, 2, 2 ) . '/' . $key . '.html';
	}

	public function on_save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::clear_all();
	}

	public function on_post_change() {
		self::clear_all();
	}

	public static function clear_all(): int {
		$count = 0;
		self::each_cache_file(
			function ( SplFileInfo $file ) use ( &$count ) {
				if ( wp_delete_file( $file->getPathname() ) ) {
					++$count;
				}
			}
		);
		self::reset_hits();
		self::log( sprintf( 'Кэш очищен, удалено файлов: %d', $count ) );
		return $count;
	}

	public function cleanup_expired() {
		$ttl = (int) $this->opts['cache_timeout'];
		if ( $ttl <= 0 ) {
			return;
		}
		$now = time();
		self::each_cache_file(
			function ( SplFileInfo $file ) use ( $ttl, $now ) {
				if ( ( $now - $file->getMTime() ) > $ttl ) {
					wp_delete_file( $file->getPathname() );
				}
			}
		);
	}

	private static function each_cache_file( callable $callback ) {
		if ( ! is_dir( MCO_CACHE_DIR ) ) {
			return;
		}
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( MCO_CACHE_DIR, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $file ) {
				if ( $file instanceof SplFileInfo && $file->isFile() && 'html' === strtolower( $file->getExtension() ) ) {
					$callback( $file );
				}
			}
		} catch ( UnexpectedValueException $e ) {
			self::log( 'Ошибка обхода кэша: ' . $e->getMessage() );
		}
	}

	private function count_hit() {
		self::add_hits( 1 );
	}

	public static function add_hits( int $delta ) {
		if ( ! is_dir( MCO_CACHE_DIR ) ) {
			return;
		}
		$file   = MCO_CACHE_DIR . '/hits.txt';
		$handle = @fopen( $file, 'c+' );
		if ( ! $handle ) {
			return;
		}
		if ( @flock( $handle, LOCK_EX ) ) {
			$current = (int) stream_get_contents( $handle );
			rewind( $handle );
			ftruncate( $handle, 0 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $handle, (string) max( 0, $current + $delta ) );
			fflush( $handle );
			flock( $handle, LOCK_UN );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
	}

	public static function get_hits(): int {
		return (int) @file_get_contents( MCO_CACHE_DIR . '/hits.txt' );
	}

	public static function reset_hits() {
		@file_put_contents( MCO_CACHE_DIR . '/hits.txt', '0', LOCK_EX );
	}

	public static function get_stats(): array {
		$files = 0;
		$size  = 0;
		self::each_cache_file(
			function ( SplFileInfo $file ) use ( &$files, &$size ) {
				++$files;
				$size += (int) $file->getSize();
			}
		);
		$opts = mco_get_settings();
		return [
			'files'     => $files,
			'size'      => $size,
			'hits'      => self::get_hits(),
			'cache_dir' => MCO_CACHE_DIR,
			'writable'  => is_dir( MCO_CACHE_DIR ) ? wp_is_writable( MCO_CACHE_DIR ) : wp_is_writable( WP_CONTENT_DIR ),
			'enabled'   => ! empty( $opts['enabled'] ),
			'log_size'  => (int) @filesize( self::get_log_path() ),
		];
	}

	public static function check_server_support(): array {
		$uploads = wp_upload_dir();
		return [
			'webp'             => ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) || ( extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) ),
			'imagick'          => extension_loaded( 'imagick' ) && class_exists( 'Imagick' ),
			'gd_webp'          => function_exists( 'imagewebp' ),
			'gzip'             => function_exists( 'gzencode' ),
			'uploads_writable' => wp_is_writable( $uploads['basedir'] ?? WP_CONTENT_DIR ),
			'cache_writable'   => is_dir( MCO_CACHE_DIR ) ? wp_is_writable( MCO_CACHE_DIR ) : wp_is_writable( WP_CONTENT_DIR ),
		];
	}

	public static function log( string $message ) {
		$file = self::get_log_path();
		if ( ! is_dir( MCO_CACHE_DIR ) && ! wp_mkdir_p( MCO_CACHE_DIR ) ) {
			return;
		}
		if ( file_exists( $file ) && filesize( $file ) > 1048576 ) {
			@file_put_contents( $file, '', LOCK_EX );
		}
		@file_put_contents( $file, '[' . gmdate( 'c' ) . '] ' . $message . "\n", FILE_APPEND | LOCK_EX );
	}

	public static function get_log_path(): string {
		return MCO_CACHE_DIR . '/mco.log';
	}

	public static function url_to_path( string $url ): ?string {
		$url = (string) strtok( $url, '?#' );
		if ( '' === $url ) {
			return null;
		}
		$uploads = wp_upload_dir();
		$bases   = array_filter( [ (string) ( $uploads['baseurl'] ?? '' ), (string) content_url(), (string) home_url( '/' ), (string) site_url( '/' ) ] );
		$path = null;
		if ( '/' === substr( $url, 0, 1 ) && '//' !== substr( $url, 0, 2 ) ) {
			$path = ABSPATH . ltrim( $url, '/' );
		} else {
			foreach ( $bases as $base ) {
				$base = rtrim( $base, '/' );
				if ( 0 === strpos( $url, $base . '/' ) ) {
					$path = ABSPATH . ltrim( substr( $url, strlen( $base ) ), '/' );
					break;
				}
			}
		}
		if ( ! $path ) {
			return null;
		}
		$real = realpath( $path );
		$root = realpath( ABSPATH );
		if ( ! $real || ! $root || 0 !== strpos( $real, $root ) || ! is_file( $real ) ) {
			return null;
		}
		return $real;
	}

	public static function install_protection_files() {
		if ( ! is_dir( MCO_CACHE_DIR ) ) {
			wp_mkdir_p( MCO_CACHE_DIR );
		}
		$index = MCO_CACHE_DIR . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
		$htaccess = MCO_CACHE_DIR . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# " . MCO_MARKER . "\n";
			$rules .= "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules );
		}
	}

	public static function update_htaccess_rules( ?array $opts = null ): bool {
		$file = ABSPATH . '.htaccess';
		if ( ! wp_is_file_mod_allowed( 'mco_htaccess' ) ) {
			return false;
		}
		$exists = file_exists( $file );
		if ( $exists && ! is_writable( $file ) ) {
			return false;
		}
		if ( ! $exists && ! self::looks_like_apache() ) {
			return false;
		}
		$content = '';
		if ( $exists ) {
			$raw = @file_get_contents( $file );
			if ( false === $raw ) {
				self::log( '.htaccess не читается, файл не изменён' );
				return false;
			}
			$content = $raw;
		}
		$content = self::strip_htaccess_block( $content );
		if ( null === $content ) {
			self::log( '.htaccess: найден BEGIN без END, файл не изменён' );
			return false;
		}
		$opts  = is_array( $opts ) ? $opts : mco_get_settings();
		$rules = '';
		if ( ! empty( $opts['gzip_enabled'] ) ) {
			$rules .= "<IfModule mod_deflate.c>\n";
			$rules .= "\tAddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/x-javascript application/json application/xml application/rss+xml image/svg+xml\n";
			$rules .= "</IfModule>\n";
		}
		if ( ! empty( $opts['browser_cache'] ) ) {
			$rules .= "<IfModule mod_expires.c>\n";
			$rules .= "\tExpiresActive On\n";
			$rules .= "\tExpiresByType image/jpeg \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType image/png \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType image/gif \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType image/webp \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType image/avif \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType image/svg+xml \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType font/woff2 \"access plus 1 year\"\n";
			$rules .= "\tExpiresByType text/css \"access plus 1 month\"\n";
			$rules .= "\tExpiresByType application/javascript \"access plus 1 month\"\n";
			$rules .= "</IfModule>\n";
			$rules .= "<IfModule mod_headers.c>\n";
			$rules .= "\t<FilesMatch \"\\.(css|js|jpg|jpeg|gif|png|webp|avif|svg|woff2|woff|ttf|otf|eot|ico)$\">\n";
			$rules .= "\t\tHeader set Cache-Control \"public, max-age=31536000, immutable\"\n";
			$rules .= "\t</FilesMatch>\n";
			$rules .= "</IfModule>\n";
		}
		$block = '';
		if ( '' !== $rules ) {
			$block = '# BEGIN ' . MCO_MARKER . "\n" . $rules . '# END ' . MCO_MARKER . "\n\n";
		}
		return false !== @file_put_contents( $file, $block . ltrim( $content ), LOCK_EX );
	}

	public static function remove_htaccess_rules() {
		$file = ABSPATH . '.htaccess';
		if ( ! file_exists( $file ) || ! is_writable( $file ) || ! wp_is_file_mod_allowed( 'mco_htaccess' ) ) {
			return;
		}
		$raw = @file_get_contents( $file );
		if ( false === $raw ) {
			return;
		}
		$content = self::strip_htaccess_block( $raw );
		if ( null === $content ) {
			return;
		}
		@file_put_contents( $file, $content, LOCK_EX );
	}

	private static function strip_htaccess_block( string $content ): ?string {
		$begin = '# BEGIN ' . MCO_MARKER;
		$end   = '# END ' . MCO_MARKER;
		if ( false === strpos( $content, $begin ) ) {
			return $content;
		}
		if ( false === strpos( $content, $end ) ) {
			return null;
		}
		$pattern = '/[ \t]*' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '[ \t]*\r?\n?/s';
		$result  = preg_replace( $pattern, '', $content, 1 );
		return is_string( $result ) ? $result : null;
	}

	private static function looks_like_apache(): bool {
		if ( function_exists( 'apache_get_modules' ) ) {
			return true;
		}
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) );
	}
}
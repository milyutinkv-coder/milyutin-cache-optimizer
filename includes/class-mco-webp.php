<?php
/**
 * WebP: конвертация при загрузке, массовая конвертация, отдача через <picture>.
 *
 * Отличия от 1.0.0:
 *  — <source srcset> собирается из srcset самого <img> (раньше адаптивные
 *    изображения теряли свои размеры и грузились одним файлом);
 *  — изображения, уже обёрнутые в <picture>, не обрабатываются повторно;
 *  — проверка поддержки WebP учитывает и Imagick, и GD.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Конвертация и отдача WebP.
 */
class MCO_WebP {

	/**
	 * Настройки.
	 *
	 * @var array<string,mixed>
	 */
	private array $opts;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		$this->opts = mco_get_settings();

		add_filter( 'mco_process_html', [ $this, 'process' ], 10 );
	}

	/**
	 * Хуки, которые нужны и в админке.
	 *
	 * @return void
	 */
	public static function register_upload_hooks() {
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'on_upload' ], 10, 2 );
	}

	/**
	 * Генерирует WebP для основного файла и всех размеров.
	 *
	 * @param array $metadata      Метаданные вложения.
	 * @param int   $attachment_id ID вложения.
	 * @return array
	 */
	public static function on_upload( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$uploads = wp_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] ) . $metadata['file'];

		self::maybe_make_webp( $base );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $base );

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					self::maybe_make_webp( $dir . '/' . $size['file'] );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Создаёт .webp рядом с изображением, если его ещё нет.
	 *
	 * @param string $file    Путь к файлу.
	 * @param int    $quality Качество (0 — из настроек).
	 * @return bool
	 */
	public static function maybe_make_webp( string $file, int $quality = 0 ): bool {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}

		$webp = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );

		if ( ! is_string( $webp ) || $webp === $file ) {
			return false;
		}

		if ( is_file( $webp ) && filesize( $webp ) > 0 ) {
			return true;
		}

		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';

		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			return false;
		}

		if ( $quality <= 0 ) {
			$settings = mco_get_settings();
			$quality  = (int) ( $settings['webp_quality'] ?? 82 );
		}

		$quality = max( 1, min( 100, $quality ) );

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$image = new Imagick( $file );
				$image->setImageFormat( 'webp' );
				$image->setImageCompressionQuality( $quality );
				$ok = $image->writeImage( $webp );
				$image->clear();
				$image->destroy();

				if ( $ok && is_file( $webp ) && filesize( $webp ) > 0 ) {
					return true;
				}
			} catch ( Throwable $e ) {
				MCO_Cache::log( 'WebP (Imagick): ' . $e->getMessage() );
			}
		}

		if ( extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) ) {
			$image = 'image/jpeg' === $mime
				? @imagecreatefromjpeg( $file ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				: @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $image ) {
				return false;
			}

			if ( 'image/png' === $mime && function_exists( 'imagepalettetotruecolor' ) ) {
				imagepalettetotruecolor( $image );
				imagealphablending( $image, true );
				imagesavealpha( $image, true );
			}

			$ok = imagewebp( $image, $webp, $quality );
			imagedestroy( $image );

			return (bool) $ok && is_file( $webp );
		}

		return false;
	}

	/**
	 * Массовая конвертация порциями (для кнопки в админке).
	 *
	 * @param int $limit Сколько файлов обработать за раз.
	 * @return array{done:int,remaining:bool}
	 */
	public static function convert_all( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );

		$ids = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_mime_type'   => [ 'image/jpeg', 'image/png' ],
				'posts_per_page'   => $limit + 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_mco_webp_done',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$remaining = count( $ids ) > $limit;
		$ids       = array_slice( $ids, 0, $limit );
		$done      = 0;

		foreach ( $ids as $id ) {
			$file = get_attached_file( (int) $id );

			if ( $file && self::maybe_make_webp( $file ) ) {
				update_post_meta( (int) $id, '_mco_webp_done', 1 );
				++$done;
			} else {
				// Помечаем как обработанное, чтобы кнопка не зацикливалась на битых файлах.
				update_post_meta( (int) $id, '_mco_webp_done', 0 );
			}
		}

		return [
			'done'      => $done,
			'remaining' => $remaining,
		];
	}

	/**
	 * Заменяет <img> на <picture> с WebP-источником.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$store = [];

		// Не трогаем то, что уже обёрнуто в <picture>.
		$html = mco_protect( $html, '#<picture\b[^>]*>.*?</picture\s*>#is', $store );

		$html = mco_preg(
			'#<img\b([^>]*)>#i',
			function ( $m ) {
				$attributes = $m[1];

				if ( false !== stripos( $attributes, 'data-no-webp' ) ) {
					return $m[0];
				}

				if ( ! preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $attributes, $src_match ) ) {
					return $m[0];
				}

				$webp_src = $this->webp_for_url( $src_match[2] );

				if ( ! $webp_src ) {
					return $m[0];
				}

				$srcset = '';

				if ( preg_match( '/\bsrcset=(["\'])([^"\']+)\1/i', $attributes, $srcset_match ) ) {
					$converted = $this->convert_srcset( $srcset_match[2] );

					if ( ! $converted ) {
						// Хотя бы один размер без WebP — не ломаем адаптивность.
						return $m[0];
					}

					$srcset = $converted;
				}

				$source = '<source type="image/webp" srcset="' . esc_attr( $srcset ?: $webp_src ) . '"';

				if ( preg_match( '/\bsizes=(["\'])([^"\']+)\1/i', $attributes, $sizes_match ) ) {
					$source .= ' sizes="' . esc_attr( $sizes_match[2] ) . '"';
				}

				$source .= '>';

				return '<picture>' . $source . $m[0] . '</picture>';
			},
			$html
		);

		return mco_restore( $html, $store );
	}

	/**
	 * URL WebP-версии, если файл существует.
	 *
	 * @param string $url URL изображения.
	 * @return string|null
	 */
	private function webp_for_url( string $url ): ?string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );

		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return null;
		}

		$path = MCO_Cache::url_to_path( $url );

		if ( ! $path || ! preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			return null;
		}

		$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );

		if ( ! is_string( $webp_path ) || ! is_file( $webp_path ) ) {
			return null;
		}

		$base     = (string) strtok( $url, '?' );
		$webp_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $base );

		if ( ! is_string( $webp_url ) || $webp_url === $base ) {
			return null;
		}

		return $webp_url;
	}

	/**
	 * Конвертирует все кандидаты srcset в WebP.
	 *
	 * @param string $srcset Значение srcset.
	 * @return string|null Null, если хотя бы один кандидат без WebP.
	 */
	private function convert_srcset( string $srcset ): ?string {
		$out = [];

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			$parts      = preg_split( '/\s+/', $candidate, 2 );
			$url        = (string) ( $parts[0] ?? '' );
			$descriptor = isset( $parts[1] ) ? ' ' . trim( (string) $parts[1] ) : '';
			$webp       = $this->webp_for_url( $url );

			if ( ! $webp ) {
				return null;
			}

			$out[] = $webp . $descriptor;
		}

		return $out ? implode( ', ', $out ) : null;
	}
}

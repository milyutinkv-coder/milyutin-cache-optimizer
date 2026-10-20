<?php
/**
 * Lazy Load изображений и iframe.
 *
 * Используется нативный loading="lazy" (поддерживается всеми актуальными
 * браузерами). JS-фолбэк из 1.0.0 был мёртвым кодом: он искал data-src,
 * который никто не проставлял, а проверка "loading" in HTMLImageElement.prototype
 * отключала его ровно в тех браузерах, где он был нужен.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Отложенная загрузка изображений/iframe.
 */
class MCO_LazyLoad {

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

		add_filter( 'mco_process_html', [ $this, 'process' ], 15 );
	}

	/**
	 * Обрабатывает HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 512 ) {
			return $html;
		}

		$skip = max( 0, (int) ( $this->opts['lazyload_skip_first'] ?? 2 ) );

		if ( ! empty( $this->opts['lazyload_images'] ) && false !== stripos( $html, '<img' ) ) {
			$html = $this->lazyload_images( $html, $skip );
		}

		if ( ! empty( $this->opts['lazyload_iframes'] ) && false !== stripos( $html, '<iframe' ) ) {
			$html = $this->lazyload_iframes( $html, min( 1, $skip ) );
		}

		return $html;
	}

	/**
	 * Добавляет loading="lazy" изображениям, кроме первых $skip.
	 *
	 * @param string $html HTML.
	 * @param int    $skip Сколько первых изображений оставить без lazy.
	 * @return string
	 */
	private function lazyload_images( string $html, int $skip ): string {
		$index = 0;

		return mco_preg(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$index, $skip ) {
				++$index;

				if ( $index <= $skip ) {
					return $m[0];
				}

				return $this->make_lazy( $m[0] );
			},
			$html
		);
	}

	/**
	 * Добавляет loading="lazy" iframe, кроме первого.
	 *
	 * @param string $html HTML.
	 * @param int    $skip Сколько первых iframe оставить без lazy.
	 * @return string
	 */
	private function lazyload_iframes( string $html, int $skip ): string {
		$index = 0;

		return mco_preg(
			'#<iframe\b[^>]*>#i',
			function ( $m ) use ( &$index, $skip ) {
				++$index;

				if ( $index <= $skip ) {
					return $m[0];
				}

				return $this->make_lazy( $m[0] );
			},
			$html
		);
	}

	/**
	 * Проставляет атрибуты ленивой загрузки, если их ещё нет.
	 *
	 * @param string $tag Тег.
	 * @return string
	 */
	private function make_lazy( string $tag ): string {
		if ( preg_match( '/\sloading\s*=/i', $tag ) ) {
			return $tag;
		}

		if ( preg_match( '/\s(data-no-lazy|data-skip-lazy|no-lazy)\b/i', $tag ) ) {
			return $tag;
		}

		$attributes = ' loading="lazy"';

		if ( ! preg_match( '/\sdecoding\s*=/i', $tag ) ) {
			$attributes .= ' decoding="async"';
		}

		$result = preg_replace( '/^<(img|iframe)/i', '<$1' . $attributes, $tag, 1 );

		return is_string( $result ) ? $result : $tag;
	}
}

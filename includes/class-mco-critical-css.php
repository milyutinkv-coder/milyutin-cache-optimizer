<?php
/**
 * Инлайн основного CSS (ускорение первой отрисовки).
 *
 * Это НЕ «настоящий» Critical CSS (для него нужен headless-браузер), поэтому
 * фича называется честно и по умолчанию выключена. Что исправлено против 1.0.0:
 *  — CSS обрезается по границе правила («}»), а не по байтам: иначе браузер
 *    проглатывал все правила после незакрытой скобки;
 *  — <style> вставляется ПОСЛЕ <meta charset>, а не перед ним (кодировка
 *    должна быть в первых 1024 байтах документа);
 *  — исходный <link> действительно переводится в асинхронный режим и получает
 *    <noscript>-фолбэк (в 1.0.0 строка $async_link просто не использовалась);
 *  — замена выполняется колбэком, поэтому «$1» и «\1» внутри CSS больше не
 *    интерпретируются как backreference.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Инлайн основного CSS-файла.
 */
class MCO_Critical_CSS {

	/**
	 * Минимальный размер инлайна.
	 *
	 * @var int
	 */
	private const MIN_INLINE = 512;

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

		add_filter( 'mco_process_html', [ $this, 'process' ], 5 );
	}

	/**
	 * Обрабатывает HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<link' ) || false === stripos( $html, '<head' ) ) {
			return $html;
		}

		$injected = '';
		$fallback = '';
		$limit    = max( 1024, (int) ( $this->opts['critical_css_limit'] ?? 15000 ) );

		$html = mco_preg(
			'#<link\b[^>]*>#i',
			function ( $m ) use ( &$injected, &$fallback, $limit ) {
				if ( '' !== $injected ) {
					return $m[0];
				}

				$tag = $m[0];

				if ( ! preg_match( '/\brel=(["\'])[^"\']*stylesheet[^"\']*\1/i', $tag ) ) {
					return $m[0]; // Не таблица стилей.
				}

				if ( preg_match( '/\bmedia=(["\'])([^"\']+)\1/i', $tag, $media ) ) {
					if ( ! in_array( strtolower( trim( $media[2] ) ), [ 'all', 'screen' ], true ) ) {
						return $m[0]; // print/prefers-* и прочее не трогаем.
					}
				}

				if ( ! preg_match( '/\bhref=(["\'])([^"\']+)\1/i', $tag, $href ) ) {
					return $m[0];
				}

				$path = MCO_Cache::url_to_path( html_entity_decode( $href[2], ENT_QUOTES ) );

				if ( ! $path ) {
					return $m[0]; // Внешний или недоступный файл.
				}

				$css = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

				if ( ! is_string( $css ) || '' === trim( $css ) ) {
					return $m[0];
				}

				if ( strlen( $css ) > $limit ) {
					$cut     = substr( $css, 0, $limit );
					$last    = strrpos( $cut, '}' );
					$partial = ( false === $last ) ? '' : substr( $cut, 0, $last + 1 );
					$partial = trim( $partial );

					if ( strlen( $partial ) < self::MIN_INLINE ) {
						return $m[0]; // Резать нечего — оставляем как есть.
					}

					$css = $partial;
				}

				$fallback = '<noscript>' . $tag . '</noscript>';
				$injected = '<style id="mco-critical-css">' . $css . '</style>';

				$inner = preg_replace( '/\s*\/?>$/', '', $tag );
				$inner = is_string( $inner ) ? $inner : $tag;

				// Убираем исходный media=, иначе в теге будет два атрибута media.
				$inner = preg_replace( '/\s+media=(["\'])[^"\']*\1/i', '', $inner );
				$inner = is_string( $inner ) ? $inner : $tag;

				return $inner . ' media="print" onload="this.media=\'all\'">';
			},
			$html
		);

		if ( '' === $injected ) {
			return $html;
		}

		$insert = $injected . $fallback;

		if ( preg_match( '#<meta[^>]+charset[^>]*>#i', $html ) ) {
			return mco_preg( '#(<meta[^>]+charset[^>]*>)#i', static fn( $m ) => $m[1] . $insert, $html, 1 );
		}

		return mco_preg( '#<head\b[^>]*>#i', static fn( $m ) => $m[0] . $insert, $html, 1 );
	}
}

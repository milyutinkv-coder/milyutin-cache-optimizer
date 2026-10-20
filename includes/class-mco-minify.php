<?php
/**
 * Безопасная минификация HTML и управление загрузкой JS.
 *
 * Что НЕ делается и почему:
 *  — объединение и минификация CSS/JS файлов удалены из плагина (в 1.0.0
 *    чекбоксы были, а кода не было вовсе). Некорректное объединение ломает
 *    сайты, поэтому фича вынесена за пределы 1.1.0;
 *  — содержимое <script>/<style>/<pre>/<textarea> не изменяется;
 *  — между inline-элементами сохраняется пробел (иначе «<span>a</span> <span>b</span>»
 *    превращалось в «ab»).
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Обработчик HTML: минификация, defer/delay JS.
 */
class MCO_Minify {

	/**
	 * Inline-элементы: пробел между ними значим для вёрстки.
	 *
	 * @var string[]
	 */
	private const INLINE_TAGS = [
		'a', 'abbr', 'audio', 'b', 'bdi', 'bdo', 'br', 'button', 'canvas', 'cite', 'code',
		'data', 'del', 'dfn', 'em', 'embed', 'i', 'iframe', 'img', 'input', 'ins', 'kbd',
		'label', 'mark', 'meter', 'noscript', 'object', 'output', 'picture', 'progress',
		'q', 'ruby', 's', 'samp', 'select', 'slot', 'small', 'source', 'span', 'strong',
		'sub', 'sup', 'svg', 'template', 'textarea', 'time', 'u', 'var', 'video', 'wbr',
	];

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

		add_filter( 'mco_process_html', [ $this, 'process' ], 20 );
	}

	/**
	 * Обрабатывает страницу.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 512 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		if ( defined( 'DONOTMINIFY' ) && DONOTMINIFY ) {
			return $html;
		}

		if ( ! empty( $this->opts['delay_js'] ) ) {
			$html = $this->apply_delay_js( $html );
		} elseif ( ! empty( $this->opts['defer_js'] ) ) {
			$html = $this->apply_defer_js( $html );
		}

		if ( ! empty( $this->opts['minify_html'] ) ) {
			$html = $this->minify_html( $html );
		}

		return $html;
	}

	// ========================================================================
	// Минификация HTML
	// ========================================================================

	/**
	 * Минификация HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function minify_html( string $html ): string {
		$store = [];

		// 1. Прячем блоки, содержимое которых менять нельзя.
		$html = mco_protect( $html, '#<(pre|textarea|script|style)\b[^>]*>.*?</\1\s*>#is', $store );

		// 2. Удаляем HTML-комментарии, кроме условных и служебных плейсхолдеров.
		$html = mco_preg( '/<!--(?!\[if|<!|\x02).*?-->/s', static fn( $m ) => '', $html );

		// 3. Сжимаем пробелы внутри текстовых узлов (атрибуты не трогаем).
		$html = $this->collapse_text( $html );

		// 4. Сжимаем пробелы между тегами, сохраняя значимый пробел для inline.
		$html = mco_preg(
			'#(<(?:/)?[a-zA-Z][^>]*>)(\s+)(<(?:/)?[a-zA-Z][^>]*>)#',
			function ( $m ) {
				if ( $this->is_inline_tag( $m[1] ) || $this->is_inline_tag( $m[3] ) ) {
					return $m[1] . ' ' . $m[3];
				}

				return $m[1] . $m[3];
			},
			$html
		);

		return trim( mco_restore( $html, $store ) );
	}

	/**
	 * Сжимает пробелы в текстовых узлах, не задевая атрибуты тегов.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function collapse_text( string $html ): string {
		// Без PREG_SPLIT_NO_EMPTY: чётность индексов должна сохраняться
		// (чётные — текст, нечётные — теги).
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return $html;
		}

		foreach ( $parts as $index => $part ) {
			if ( 0 !== $index % 2 ) {
				continue; // Это тег.
			}

			$clean = preg_replace( '/[ \t]+/', ' ', $part );
			$clean = is_string( $clean ) ? $clean : $part;
			$clean = preg_replace( '/\s*\r?\n\s*/', ' ', $clean );

			$parts[ $index ] = is_string( $clean ) ? $clean : $part;
		}

		return implode( '', $parts );
	}

	/**
	 * Inline-ли тег.
	 *
	 * @param string $tag Тег целиком.
	 * @return bool
	 */
	private function is_inline_tag( string $tag ): bool {
		if ( ! preg_match( '#^</?\s*([a-zA-Z0-9:-]+)#', $tag, $matches ) ) {
			return false;
		}

		return in_array( strtolower( $matches[1] ), self::INLINE_TAGS, true );
	}

	// ========================================================================
	// Управление загрузкой JS
	// ========================================================================

	/**
	 * Добавляет defer к внешним скриптам (кроме исключённых).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function apply_defer_js( string $html ): string {
		$exclude = mco_lines( $this->opts['defer_js_exclude'] ?? '' );

		return mco_preg(
			'#<script\b([^>]*?)\bsrc=(["\'])([^"\']+)\2([^>]*)>\s*</script>#i',
			static function ( $m ) use ( $exclude ) {
				$attributes = $m[1] . $m[4];

				// defer/async уже есть?
				if ( preg_match( '/[\s"\'](defer|async)([\s>"\']|$)/i', ' ' . $attributes . ' ' ) ) {
					return $m[0];
				}

				foreach ( $exclude as $needle ) {
					if ( false !== stripos( $m[3], $needle ) ) {
						return $m[0];
					}
				}

				return '<script' . $m[1] . 'src=' . $m[2] . $m[3] . $m[2] . $m[4] . ' defer></script>';
			},
			$html
		);
	}

	/**
	 * Откладывает внешние скрипты до первого взаимодействия.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function apply_delay_js( string $html ): string {
		$exclude = mco_lines( $this->opts['delay_js_exclude'] ?? '' );

		$html = mco_preg(
			'#<script\b([^>]*?)\bsrc=(["\'])([^"\']+)\2([^>]*)>\s*</script>#i',
			static function ( $m ) use ( $exclude ) {
				foreach ( $exclude as $needle ) {
					if ( false !== stripos( $m[3], $needle ) ) {
						return $m[0];
					}
				}

				// Убираем исходный type=, чтобы не было двух атрибутов.
				$before = preg_replace( '/\stype=(["\']).*?\1/i', '', $m[1] );
				$after  = preg_replace( '/\stype=(["\']).*?\1/i', '', $m[4] );

				return '<script' . $before . ' type="mco/delayed" data-mco-src=' . $m[2] . $m[3] . $m[2] . $after . '></script>';
			},
			$html
		);

		$loader = '<script id="mco-delay-js">(function(){'
			. 'var SEL="script[type=\"mco/delayed\"][data-mco-src]";var done=false;'
			. 'function load(){if(done){return;}done=true;'
			. 'var list=document.querySelectorAll(SEL);'
			. 'for(var i=0;i<list.length;i++){var s=list[i];var n=document.createElement("script");'
			. 'for(var a=0;a<s.attributes.length;a++){var at=s.attributes[a];'
			. 'if(at.name==="type"||at.name==="src"||at.name==="data-mco-src"){continue;}'
			. 'n.setAttribute(at.name,at.value);}'
			. 'n.async=false;n.src=s.getAttribute("data-mco-src");'
			. 'if(s.parentNode){s.parentNode.replaceChild(n,s);}}}'
			. 'var events=["mousemove","touchstart","keydown","wheel","scroll","click"];'
			. 'for(var e=0;e<events.length;e++){window.addEventListener(events[e],load,{once:true,passive:true});}'
			. 'setTimeout(load,5000);})();</script>';

		if ( false === stripos( $html, '</head>' ) ) {
			return $html;
		}

		return mco_preg( '#</head>#i', static fn() => $loader . '</head>', $html, 1 );
	}
}

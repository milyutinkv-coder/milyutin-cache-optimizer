<?php
/**
 * Site Health: собственные тесты плагина и отладочная информация.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Тесты и отладочная информация Site Health.
 */
class MCO_Site_Health {

	/**
	 * Конструктор.
	 */
	public function __construct() {
		add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
		add_filter( 'debug_information', [ $this, 'debug_information' ] );
	}

	/**
	 * Регистрирует тесты.
	 *
	 * @param array<string,mixed> $tests Тесты.
	 * @return array<string,mixed>
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['mco_page_cache'] = [
			'label' => __( 'Кэш страниц (Milyutin Cache Optimizer)', 'milyutin-cache-optimizer-lite' ),
			'test'  => [ $this, 'test_page_cache' ],
		];

		return $tests;
	}

	/**
	 * Тест кэша страниц.
	 *
	 * @return array<string,mixed>
	 */
	public function test_page_cache(): array {
		$stats   = MCO_Cache::get_stats();
		$actions = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=mco-settings' ) ),
			esc_html__( 'Открыть настройки Milyutin Cache', 'milyutin-cache-optimizer-lite' )
		);

		$result = [
			'label'       => __( 'Файловый кэш страниц работает', 'milyutin-cache-optimizer-lite' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Производительность', 'milyutin-cache-optimizer-lite' ),
				'color' => 'blue',
			],
			'description' => sprintf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: files count, 2: cache size, 3: hits count */
						__( 'В кэше %1$d страниц (%2$s), отдано из кэша: %3$d запросов.', 'milyutin-cache-optimizer-lite' ),
						(int) $stats['files'],
						(string) size_format( (int) $stats['size'], 2 ),
						(int) $stats['hits']
					)
				)
			),
			'actions'     => $actions,
			'test'        => 'mco_page_cache',
		];

		if ( ! $stats['enabled'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Кэш страниц выключен', 'milyutin-cache-optimizer-lite' );
			$result['description'] = '<p>' . esc_html__( 'Включите кэширование на странице настроек плагина.', 'milyutin-cache-optimizer-lite' ) . '</p>';

			return $result;
		}

		if ( ! $stats['writable'] ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Папка кэша недоступна для записи', 'milyutin-cache-optimizer-lite' );
			$result['description'] = '<p>' . esc_html( sprintf( /* translators: %s: path to cache directory */ __( 'Нет прав на запись в %s. Кэш работать не будет.', 'milyutin-cache-optimizer-lite' ), (string) $stats['cache_dir'] ) ) . '</p>';

			return $result;
		}

		if ( (int) $stats['files'] < 1 ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Кэш страниц пуст', 'milyutin-cache-optimizer-lite' );
			$result['description'] = '<p>' . esc_html__( 'Файлов пока нет. Откройте любую страницу сайта как гость или запустите Preload в настройках плагина.', 'milyutin-cache-optimizer-lite' ) . '</p>';

			return $result;
		}

		return $result;
	}

	/**
	 * Отладочная информация (Site Health → Информация).
	 *
	 * @param array<string,mixed> $info Секции.
	 * @return array<string,mixed>
	 */
	public function debug_information( array $info ): array {
		$stats = MCO_Cache::get_stats();
		$env   = MCO_Cache::check_server_support();
		$opts  = mco_get_settings();

		$info['milyutin-cache-optimizer-lite'] = [
			'label'       => __( 'Milyutin Cache Optimizer', 'milyutin-cache-optimizer-lite' ),
			'description' => '',
			'show_count'  => false,
			'fields'      => [
				'enabled'      => [
					'label' => __( 'Кэш страниц', 'milyutin-cache-optimizer-lite' ),
					'value' => ! empty( $opts['enabled'] ) ? __( 'включён', 'milyutin-cache-optimizer-lite' ) : __( 'выключен', 'milyutin-cache-optimizer-lite' ),
				],
				'files'        => [
					'label' => __( 'Файлов в кэше', 'milyutin-cache-optimizer-lite' ),
					'value' => (string) $stats['files'],
				],
				'cache_size'   => [
					'label' => __( 'Размер кэша', 'milyutin-cache-optimizer-lite' ),
					'value' => (string) size_format( (int) $stats['size'], 2 ),
				],
				'cache_hits'   => [
					'label' => __( 'Отдано из кэша', 'milyutin-cache-optimizer-lite' ),
					'value' => (string) $stats['hits'],
				],
				'cache_dir'    => [
					'label' => __( 'Папка кэша', 'milyutin-cache-optimizer-lite' ),
					'value' => (string) $stats['cache_dir'] . ( $stats['writable'] ? '' : ' (' . __( 'нет прав на запись', 'milyutin-cache-optimizer-lite' ) . ')' ),
				],
				'webp'         => [
					'label' => __( 'WebP', 'milyutin-cache-optimizer-lite' ),
					'value' => $env['webp'] ? __( 'поддерживается', 'milyutin-cache-optimizer-lite' ) : __( 'не поддерживается', 'milyutin-cache-optimizer-lite' ),
				],
			],
		];

		return $info;
	}
}
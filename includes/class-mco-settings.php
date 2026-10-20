<?php
/**
 * Страница настроек и AJAX-обработчики.
 *
 * @package MilyutinCacheOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Админка плагина (Lite версия).
 */
class MCO_Settings {

	/**
	 * Слаг страницы.
	 *
	 * @var string
	 */
	private const PAGE = 'mco-settings';

	/**
	 * Конструктор.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_mco_clear_cache', [ $this, 'ajax_clear_cache' ] );
		add_action( 'wp_ajax_mco_convert_webp', [ $this, 'ajax_convert_webp' ] );
		add_action( 'wp_ajax_mco_download_log', [ $this, 'ajax_download_log' ] );
	}

	/**
	 * Пункт меню.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Milyutin Cache Optimizer', 'milyutin-cache-optimizer-lite' ),
			__( 'Milyutin Cache', 'milyutin-cache-optimizer-lite' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ],
			'dashicons-performance',
			80
		);
	}

	/**
	 * Стили и скрипты только на нашей странице.
	 *
	 * @param string $hook Текущий экран.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'mco-admin', MCO_PLUGIN_URL . 'assets/css/admin.css', [], MCO_VERSION );
		wp_enqueue_script( 'mco-admin', MCO_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], MCO_VERSION, true );

		wp_localize_script(
			'mco-admin',
			'MCO',
			[
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'mco_ajax' ),
				'i18n'  => [
					'confirm_clear'   => __( 'Очистить весь кэш страниц?', 'milyutin-cache-optimizer-lite' ),
					'confirm_webp'    => __( 'Начать конвертацию изображений в WebP? Процесс может занять время.', 'milyutin-cache-optimizer-lite' ),
					'running'         => __( 'Выполняется…', 'milyutin-cache-optimizer-lite' ),
					'done'            => __( 'Готово', 'milyutin-cache-optimizer-lite' ),
					'error'           => __( 'Ошибка', 'milyutin-cache-optimizer-lite' ),
					'webp_progress'   => __( 'Сконвертировано: %d. Продолжаю…', 'milyutin-cache-optimizer-lite' ),
				],
			]
		);
	}

	/**
	 * Регистрация опции.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'mco_settings_group',
			MCO_OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => mco_default_settings(),
			]
		);
	}

	/**
	 * Санитизация настроек.
	 *
	 * @param mixed $input Новые значения.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = mco_default_settings();
		$out      = mco_get_settings();

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$checkboxes = [
			'enabled',
			'cache_mobile',
			'cache_logged_in',
			'minify_html',
			'defer_js',
			'delay_js',
			'lazyload_images',
			'lazyload_iframes',
			'webp_enabled',
			'critical_css_enabled',
			'gzip_enabled',
			'browser_cache',
		];

		foreach ( $checkboxes as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		$out['cache_timeout']       = max( 60, min( 604800, (int) ( $input['cache_timeout'] ?? $defaults['cache_timeout'] ) ) );
		$out['lazyload_skip_first'] = max( 0, min( 10, (int) ( $input['lazyload_skip_first'] ?? $defaults['lazyload_skip_first'] ) ) );
		$out['webp_quality']        = max( 1, min( 100, (int) ( $input['webp_quality'] ?? $defaults['webp_quality'] ) ) );
		$out['critical_css_limit']  = max( 1024, min( 512000, (int) ( $input['critical_css_limit'] ?? $defaults['critical_css_limit'] ) ) );

		$out['exclude_urls']      = sanitize_textarea_field( (string) ( $input['exclude_urls'] ?? '' ) );
		$out['cache_query_ignore'] = sanitize_textarea_field( (string) ( $input['cache_query_ignore'] ?? '' ) );
		$out['defer_js_exclude']  = sanitize_textarea_field( (string) ( $input['defer_js_exclude'] ?? '' ) );
		$out['delay_js_exclude']  = sanitize_textarea_field( (string) ( $input['delay_js_exclude'] ?? '' ) );

		// Правила .htaccess собираем по новым значениям.
		MCO_Cache::install_protection_files();
		MCO_Cache::update_htaccess_rules( $out );

		return $out;
	}

	// ========================================================================
	// AJAX
	// ========================================================================

	/**
	 * Проверка прав и nonce.
	 *
	 * @return void
	 */
	private function verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав.', 'milyutin-cache-optimizer-lite' ) ], 403 );
		}

		check_ajax_referer( 'mco_ajax', 'nonce' );
	}

	/**
	 * Очистка кэша страниц.
	 *
	 * @return void
	 */
	public function ajax_clear_cache() {
		$this->verify();
		$count = MCO_Cache::clear_all();

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of files deleted */
					__( 'Очищено файлов: %d', 'milyutin-cache-optimizer-lite' ),
					$count
				),
			]
		);
	}

	/**
	 * Порция массовой конвертации WebP.
	 *
	 * @return void
	 */
	public function ajax_convert_webp() {
		$this->verify();

		$result = MCO_WebP::convert_all( 20 );

		wp_send_json_success(
			[
				'done'      => (int) $result['done'],
				'remaining' => (bool) $result['remaining'],
				'message'   => sprintf(
					/* translators: %d: number of converted files */
					__( 'Сконвертировано: %d', 'milyutin-cache-optimizer-lite' ),
					(int) $result['done']
				),
			]
		);
	}

	/**
	 * Скачивание лога.
	 *
	 * @return void
	 */
	public function ajax_download_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'mco_ajax' );

		$log = MCO_Cache::get_log_path();

		if ( ! file_exists( $log ) ) {
			wp_die( esc_html__( 'Лог пуст.', 'milyutin-cache-optimizer-lite' ), '', [ 'response' => 404 ] );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Length: ' . (int) filesize( $log ) );
		header( 'Content-Disposition: attachment; filename="mco-log.txt"' );
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $log );
		exit;
	}

	// ========================================================================
	// Рендер страницы
	// ========================================================================

	/**
	 * Форма настроек.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$o     = mco_get_settings();
		$stats = MCO_Cache::get_stats();
		$env   = MCO_Cache::check_server_support();
		$name  = MCO_OPTION_KEY;
		?>
		<div class="wrap mco-wrap">
			<h1>⚡ Milyutin Cache Optimizer <span style="font-size:14px; font-weight:normal; background:#eef3f7; padding:2px 8px; border-radius:4px;">Lite</span></h1>
			<p class="mco-lead"><?php esc_html_e( 'Кэш страниц, минификация HTML, WebP, Lazy Load и инлайн основного CSS.', 'milyutin-cache-optimizer-lite' ); ?></p>

			<div class="mco-brand">
				<div class="mco-brand-text">
					<strong><?php esc_html_e( 'Автор:', 'milyutin-cache-optimizer-lite' ); ?></strong> Константин Милютин<br>
					<strong><?php esc_html_e( 'Сайт:', 'milyutin-cache-optimizer-lite' ); ?></strong> <a href="https://milyutin-codes.ru" target="_blank" rel="noopener">milyutin-codes.ru</a><br>
					<strong><?php esc_html_e( 'ВК-группа:', 'milyutin-cache-optimizer-lite' ); ?></strong> <a href="https://vk.com/milyutin_codes" target="_blank" rel="noopener">vk.com/milyutin_codes</a>
				</div>
				<div class="mco-brand-badge">v<?php echo esc_html( MCO_VERSION ); ?></div>
			</div>

			<?php settings_errors(); ?>

			<div class="mco-stats">
				<div class="mco-stat"><span class="mco-stat-num"><?php echo esc_html( (string) $stats['files'] ); ?></span><span><?php esc_html_e( 'страниц в кэше', 'milyutin-cache-optimizer-lite' ); ?></span></div>
				<div class="mco-stat"><span class="mco-stat-num"><?php echo esc_html( size_format( (int) $stats['size'], 2 ) ); ?></span><span><?php esc_html_e( 'размер кэша', 'milyutin-cache-optimizer-lite' ); ?></span></div>
				<div class="mco-stat"><span class="mco-stat-num"><?php echo esc_html( (string) $stats['hits'] ); ?></span><span><?php esc_html_e( 'отдано из кэша', 'milyutin-cache-optimizer-lite' ); ?></span></div>
			</div>

			<?php if ( ! $stats['writable'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %s: path to cache directory */ __( 'Нет прав на запись в %s — кэш работать не будет.', 'milyutin-cache-optimizer-lite' ), (string) $stats['cache_dir'] ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $env['webp'] ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'На сервере нет Imagick/GD с поддержкой WebP — конвертация работать не будет.', 'milyutin-cache-optimizer-lite' ); ?></p></div>
			<?php endif; ?>

			<div class="mco-actions">
				<button type="button" class="button button-primary" id="mco-clear-cache">🧹 <?php esc_html_e( 'Очистить кэш', 'milyutin-cache-optimizer-lite' ); ?></button>
				<button type="button" class="button" id="mco-convert-webp">🖼 <?php esc_html_e( 'Конвертировать в WebP', 'milyutin-cache-optimizer-lite' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=mco_download_log' ), 'mco_ajax' ) ); ?>">📄 <?php esc_html_e( 'Скачать лог', 'milyutin-cache-optimizer-lite' ); ?></a>
				<span class="mco-spinner spinner"></span>
				<span class="mco-action-result" role="status" aria-live="polite"></span>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'mco_settings_group' ); ?>

				<h2 class="mco-section"><?php esc_html_e( 'Кэш страниц', 'milyutin-cache-optimizer-lite' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Плагин включён', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'enabled', __( 'Включить кэширование и оптимизацию', 'milyutin-cache-optimizer-lite' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Мобильные устройства', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'cache_mobile', __( 'Отдельная копия кэша для мобильных', 'milyutin-cache-optimizer-lite' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mco-cache-timeout"><?php esc_html_e( 'Время жизни кэша', 'milyutin-cache-optimizer-lite' ); ?></label></th>
						<td>
							<input type="number" min="60" max="604800" step="60" id="mco-cache-timeout" name="<?php echo esc_attr( $name ); ?>[cache_timeout]" value="<?php echo esc_attr( (string) $o['cache_timeout'] ); ?>"> <?php esc_html_e( 'секунд', 'milyutin-cache-optimizer-lite' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mco-exclude-urls"><?php esc_html_e( 'Исключения по URL', 'milyutin-cache-optimizer-lite' ); ?></label></th>
						<td>
							<textarea id="mco-exclude-urls" rows="5" class="large-text code" name="<?php echo esc_attr( $name ); ?>[exclude_urls]"><?php echo esc_textarea( (string) $o['exclude_urls'] ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2 class="mco-section"><?php esc_html_e( 'HTML и JavaScript', 'milyutin-cache-optimizer-lite' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Минификация HTML', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'minify_html', __( 'Сжимать HTML', 'milyutin-cache-optimizer-lite' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'defer для скриптов', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'defer_js', __( 'Добавлять defer к внешним скриптам', 'milyutin-cache-optimizer-lite' ) ); ?>
							<p class="description"><?php esc_html_e( 'Включайте после проверки, чтобы не сломать сайт.', 'milyutin-cache-optimizer-lite' ); ?></p>
							<textarea rows="3" class="large-text code" name="<?php echo esc_attr( $name ); ?>[defer_js_exclude]"><?php echo esc_textarea( (string) $o['defer_js_exclude'] ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2 class="mco-section"><?php esc_html_e( 'Изображения', 'milyutin-cache-optimizer-lite' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazy Load', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'lazyload_images', __( 'Отложенная загрузка изображений', 'milyutin-cache-optimizer-lite' ) ); ?><br>
							<?php $this->checkbox( $o, $name, 'lazyload_iframes', __( 'Отложенная загрузка iframe', 'milyutin-cache-optimizer-lite' ) ); ?>
							<p class="description"><?php esc_html_e( 'Используется нативный loading="lazy".', 'milyutin-cache-optimizer-lite' ); ?></p>
							<label><input type="number" min="0" max="10" name="<?php echo esc_attr( $name ); ?>[lazyload_skip_first]" value="<?php echo esc_attr( (string) $o['lazyload_skip_first'] ); ?>"> <?php esc_html_e( 'первых изображений не трогать', 'milyutin-cache-optimizer-lite' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'webp_enabled', __( 'Конвертировать изображения в WebP', 'milyutin-cache-optimizer-lite' ) ); ?>
							<label><input type="number" min="1" max="100" name="<?php echo esc_attr( $name ); ?>[webp_quality]" value="<?php echo esc_attr( (string) $o['webp_quality'] ); ?>"> <?php esc_html_e( 'качество', 'milyutin-cache-optimizer-lite' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Инлайн основного CSS', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'critical_css_enabled', __( 'Встраивать начало первого CSS-файла', 'milyutin-cache-optimizer-lite' ) ); ?>
							<label><input type="number" min="1024" max="512000" step="1024" name="<?php echo esc_attr( $name ); ?>[critical_css_limit]" value="<?php echo esc_attr( (string) $o['critical_css_limit'] ); ?>"> <?php esc_html_e( 'байт максимум', 'milyutin-cache-optimizer-lite' ); ?></label>
						</td>
					</tr>
				</table>

				<h2 class="mco-section"><?php esc_html_e( 'Сервер', 'milyutin-cache-optimizer-lite' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Сжатие и кэш браузера', 'milyutin-cache-optimizer-lite' ); ?></th>
						<td>
							<?php $this->checkbox( $o, $name, 'gzip_enabled', __( 'Включить сжатие в .htaccess (mod_deflate)', 'milyutin-cache-optimizer-lite' ) ); ?><br>
							<?php $this->checkbox( $o, $name, 'browser_cache', __( 'Кэш статики в браузере (mod_expires)', 'milyutin-cache-optimizer-lite' ) ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Сохранить настройки', 'milyutin-cache-optimizer-lite' ) ); ?>
			</form>

			<!-- Блок Pro-апселла -->
			<div class="mco-limits" style="background: linear-gradient(135deg, #f1f8ff, #ffffff); border: 2px dashed #2271b1; margin-top: 40px;">
				<h3 style="color: #2271b1; margin-top:0;">🚀 Хотите больше возможностей?</h3>
				<p>Откройте максимум производительности с <strong>Milyutin Cache Optimizer Pro</strong>:</p>
				<ul style="font-size:14px; line-height: 1.6;">
					<li>⚡ <strong>Постоянный объектный кэш</strong> (Redis, Memcached) — снижение нагрузки на БД до 90%.</li>
					<li>☁️ <strong>Интеграция с Cloudflare CDN</strong> — автоматический сброс кэша на периферии.</li>
					<li>🔄 <strong>Автопрогрев (Preload)</strong> — кэш всегда готов к приходу посетителя.</li>
					<li>🗑️ <strong>Умная очистка БД</strong> — удаление ревизий и спама по расписанию.</li>
				</ul>
				<p style="margin-top: 20px;">
					<a href="https://milyutin-codes.ru" target="_blank" class="button button-primary button-hero" style="background: #2271b1; border-color: #2271b1;">Узнать о Pro-версии</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Чекбокс.
	 *
	 * @param array<string,mixed> $opts  Настройки.
	 * @param string              $name  Имя опции.
	 * @param string              $key   Ключ.
	 * @param string              $label Подпись.
	 * @return void
	 */
	private function checkbox( array $opts, string $name, string $key, string $label ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $key ),
			checked( ! empty( $opts[ $key ] ), true, false ),
			esc_html( $label )
		);
	}
}
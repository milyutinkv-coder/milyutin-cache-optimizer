(function ($) {
	'use strict';

	var timer = null;

	function result(text) {
		var $box = $('.mco-action-result');

		$box.text(text || '');

		if (timer) {
			window.clearTimeout(timer);
		}

		if (text) {
			timer = window.setTimeout(function () {
				$box.text('');
			}, 10000);
		}
	}

	function setBusy(busy) {
		$('.mco-actions .button').prop('disabled', !!busy);
		$('.mco-spinner').toggleClass('is-active', !!busy);
	}

	function request(action) {
		return $.post(MCO.ajax, {
			action: action,
			nonce: MCO.nonce
		});
	}

	function errorText(xhr) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}

		return MCO.i18n.error;
	}

	function run(action, confirmMsg) {
		if (confirmMsg && !window.confirm(confirmMsg)) {
			return;
		}

		setBusy(true);
		result(MCO.i18n.running);

		request(action)
			.done(function (resp) {
				if (resp && resp.success) {
					result((resp.data && resp.data.message) || MCO.i18n.done);
				} else {
					result((resp && resp.data && resp.data.message) || MCO.i18n.error);
				}
			})
			.fail(function (xhr) {
				result(errorText(xhr));
			})
			.always(function () {
				setBusy(false);
			});
	}

	function convertWebp() {
		if (!window.confirm(MCO.i18n.confirm_webp)) {
			return;
		}

		setBusy(true);

		var total = 0;

		function step() {
			request('mco_convert_webp')
				.done(function (resp) {
					if (!resp || !resp.success) {
						result(MCO.i18n.error);
						setBusy(false);
						return;
					}

					total += parseInt(resp.data.done, 10) || 0;
					result(MCO.i18n.webp_progress.replace('%d', total));

					if (resp.data.remaining) {
						step();
					} else {
						result(MCO.i18n.done + ': ' + total);
						setBusy(false);
					}
				})
				.fail(function (xhr) {
					result(errorText(xhr));
					setBusy(false);
				});
		}

		step();
	}

	$(function () {
		// Очистка кэша
		$('#mco-clear-cache').on('click', function () {
			run('mco_clear_cache', MCO.i18n.confirm_clear);
		});

		// Массовая конвертация WebP
		$('#mco-convert-webp').on('click', convertWebp);
	});
})(jQuery);
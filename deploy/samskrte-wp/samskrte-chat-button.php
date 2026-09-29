<?php
/**
 * Plugin Name: Samskrte Chat Button (H5451)
 * Description: Плавающая кнопка «Спросить» на витрине samskrtam.ru — открывает iframe живого чата поддержки samskrte.ru (Systema, GET /chat/embed). mu-plugin: грузится всегда, активация не нужна. Без внешних CDN — один инлайн-скрипт.
 * Version:     1.0.0
 * Author:      Systema agents (H5451)
 *
 * Канонический источник: Systema-Sanscriticum deploy/samskrte-wp/samskrte-chat-button.php
 * Путь на хосте .95: www/samskrtam.ru/wp-content/mu-plugins/samskrte-chat-button.php
 *
 * Сторожевые скрипты .92 (tamper_watch / hijack_heal / home_repin) этот файл
 * не пишут и не удаляют (проверено 24-09-2026 чтением исходников):
 * hijack_heal пишет только fast-home.php/.user.ini, home_repin — только
 * index.html в корне; тампер-вотч только читает HTTP-поверхности.
 *
 * Известное следствие контейнмент-состояния (H4017): GET / отдает статический
 * снимок index.html — на статической главной кнопки нет; на всех динамических
 * страницах WP (товары, магазин, категории) кнопка есть.
 *
 * Iframe: https://samskrte.ru/chat/embed?page=<текущий URL> — Systema-сторона
 * кладет URL товара в приветствие и телеметрию (куратор видит товар в
 * Helpdesk). src ставится лениво при первом открытии — страница не грузит
 * чат до клика. CSP samskrte.ru ограничивает frame-ancestors своим доменом.
 */
if (! defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', function () {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    // Текущий URL витрины — уходит в Systema как ?page= (контекст товара).
    $scheme = (is_ssl() ? 'https' : 'http').'://';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    // esc_url_raw (не esc_url): URL-контекст без entity-кодирования `&`,
    // иначе multi-param URL доезжал бы до Systema как `&#038;` (review P3).
    $page = esc_url_raw($scheme.$host.$uri);

    $embed = 'https://samskrte.ru/chat/embed?page='.rawurlencode($page);
    ?>
	<style>
		#samscw-btn {
			position: fixed; right: 20px; bottom: 20px; z-index: 99999;
			width: auto; height: 52px; padding: 0 22px; border: none; border-radius: 26px;
			cursor: pointer; display: flex; align-items: center; gap: 8px;
			background: linear-gradient(135deg, #E85C24, #c9491a); color: #fff;
			font-size: 16px; font-weight: 700; font-family: inherit;
			box-shadow: 0 6px 20px rgba(232, 92, 36, .45);
			transition: transform .2s ease, box-shadow .2s ease;
		}
		#samscw-btn:hover { transform: scale(1.05); box-shadow: 0 8px 26px rgba(232, 92, 36, .6); }
		#samscw-btn:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
		#samscw-wrap {
			position: fixed; right: 20px; bottom: 84px; z-index: 99999;
			width: 380px; max-width: calc(100vw - 40px); height: 560px;
			max-height: calc(100vh - 110px);
			background: #111827; border-radius: 16px; overflow: hidden;
			box-shadow: 0 18px 50px rgba(0, 0, 0, .5);
		}
		#samscw-frame { width: 100%; height: 100%; border: 0; display: block; }
		@media (max-width: 480px) {
			#samscw-btn { right: 14px; bottom: 14px; }
			#samscw-wrap { right: 14px; bottom: 76px; width: calc(100vw - 28px); }
		}
	</style>
	<button id="samscw-btn" type="button" aria-expanded="false" aria-controls="samscw-wrap">
		<span aria-hidden="true">💬</span> Спросить
	</button>
	<div id="samscw-wrap" role="dialog" aria-label="Чат с поддержкой" hidden>
		<iframe id="samscw-frame"
			title="Чат с поддержкой"
			data-src="<?php echo esc_attr($embed); ?>"
			loading="lazy"
			referrerpolicy="no-referrer-when-downgrade"
		></iframe>
	</div>
	<script>
	(function () {
		var btn  = document.getElementById('samscw-btn');
		var wrap = document.getElementById('samscw-wrap');
		var frame = document.getElementById('samscw-frame');
		if (!btn || !wrap || !frame) return;
		var open = false;
		btn.addEventListener('click', function () {
			open = !open;
			if (open && !frame.src) { frame.src = frame.getAttribute('data-src'); } // ленивая загрузка
			wrap.hidden = !open;
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	})();
	</script>
	<?php
}, 20);

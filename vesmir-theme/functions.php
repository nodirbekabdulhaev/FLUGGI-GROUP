<?php
defined('ABSPATH') || exit;

define('VZ_VERSION', '1.0.0');

require_once __DIR__ . '/inc/defaults.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/acf-fields.php';

add_action('after_setup_theme', function () {
	load_theme_textdomain('vesmir', get_template_directory() . '/languages');
	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
	add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
	add_theme_support('custom-logo', ['height' => 60, 'width' => 220, 'flex-width' => true, 'flex-height' => true]);
	register_nav_menus([
		'primary' => 'Главное меню',
		'footer'  => 'Меню в футере',
	]);
});

add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('vz-fonts', 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap', [], null);
	wp_enqueue_style('vz-main', get_template_directory_uri() . '/assets/css/main.css', ['vz-fonts'], VZ_VERSION);
	wp_enqueue_script('vz-main', get_template_directory_uri() . '/assets/js/main.js', [], VZ_VERSION, ['in_footer' => true, 'strategy' => 'defer']);

	$css = sprintf(
		':root{--primary:%s;--accent:%s;--dark:%s}',
		esc_attr(vz_o('color_primary')), esc_attr(vz_o('color_accent')), esc_attr(vz_o('color_dark'))
	);
	wp_add_inline_style('vz-main', $css);
});

add_action('wp_head', function () {
	$code = function_exists('get_field') ? get_field('head_code', 'option') : '';
	if ($code) echo $code . "\n"; // phpcs:ignore -- намеренно: код счётчиков из админки
}, 99);

add_action('wp_footer', function () {
	$code = function_exists('get_field') ? get_field('body_code', 'option') : '';
	if ($code) echo $code . "\n"; // phpcs:ignore
}, 99);

/** Меню по умолчанию, пока в админке не собрано своё. */
function vz_menu_fallback() {
	$items = [
		'#gallery'  => 'Галерея работ',
		'#about'    => 'О нас',
		'#segment'  => 'Для кого?',
		'#blog'     => 'Наш блог',
	];
	echo '<ul class="nav__list">';
	foreach ($items as $hash => $label) {
		printf('<li><a href="%s">%s</a></li>', esc_url(vz_anchor($hash)), esc_html($label));
	}
	echo '</ul>';
}

function vz_menu($location, $class = 'nav__list') {
	wp_nav_menu([
		'theme_location' => $location,
		'container'      => false,
		'menu_class'     => $class,
		'fallback_cb'    => 'vz_menu_fallback',
		'depth'          => 1,
	]);
}

/** Обработка форм (заявка + подписка). */
function vz_handle_lead() {
	$back = wp_get_referer() ?: home_url('/');
	$back = remove_query_arg(['sent', 'err'], $back);

	if (!isset($_POST['vz_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vz_nonce'])), 'vz_lead')) {
		wp_safe_redirect(add_query_arg('err', 1, $back) . '#sorov'); exit;
	}
	if (!empty($_POST['vz_website'])) { // honeypot
		wp_safe_redirect(add_query_arg('sent', 1, $back)); exit;
	}

	$type  = (isset($_POST['vz_type']) && $_POST['vz_type'] === 'subscribe') ? 'subscribe' : 'lead';
	$name  = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
	$phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
	$email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
	$msg   = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

	if (($type === 'subscribe' && !is_email($email)) || ($type === 'lead' && $phone === '' && !is_email($email))) {
		wp_safe_redirect(add_query_arg('err', 1, $back) . '#sorov'); exit;
	}

	$to = function_exists('get_field') ? get_field('form_recipient', 'option') : '';
	$to = is_email($to) ? $to : get_option('admin_email');

	$subject = ($type === 'subscribe' ? 'Подписка на новости' : 'Новая заявка') . ' — ' . get_bloginfo('name');
	$body = "Имя: $name\nТелефон: $phone\nE-mail: $email\n\nСообщение:\n$msg\n\nСтраница: $back";
	$headers = is_email($email) ? ['Reply-To: ' . $email] : [];
	wp_mail($to, $subject, $body, $headers);

	wp_safe_redirect(add_query_arg('sent', 1, $back) . '#sorov'); exit;
}
add_action('admin_post_nopriv_vz_lead', 'vz_handle_lead');
add_action('admin_post_vz_lead', 'vz_handle_lead');

function vz_form_notice() {
	if (isset($_GET['sent'])) {
		$ok = function_exists('get_field') ? get_field('form_success', 'option') : '';
		echo '<p class="notice notice--ok">' . esc_html($ok ?: 'Спасибо! Мы свяжемся с вами в ближайшее время.') . '</p>';
	} elseif (isset($_GET['err'])) {
		echo '<p class="notice notice--err">Не удалось отправить. Проверьте поля и повторите.</p>';
	}
}

<?php
/**
 * Регистрация ACF-полей кодом: после активации темы всё появляется в админке само.
 * Работает с бесплатным ACF; повторители (repeater) и опции — ACF PRO.
 */
defined('ABSPATH') || exit;

add_action('acf/init', function () {
	if (!function_exists('acf_add_local_field_group')) return;

	if (function_exists('acf_add_options_page')) {
		acf_add_options_page([
			'page_title' => 'Настройки сайта',
			'menu_title' => 'Настройки сайта',
			'menu_slug'  => 'vesmir-settings',
			'capability' => 'edit_posts',
			'icon_url'   => 'dashicons-admin-generic',
			'position'   => 3,
		]);
	}

	$t = function ($key, $label, $name, $extra = []) {
		return array_merge(['key' => $key, 'label' => $label, 'name' => $name, 'type' => 'text'], $extra);
	};
	$tab = function ($key, $label) {
		return ['key' => $key, 'label' => $label, 'name' => '', 'type' => 'tab', 'placement' => 'top'];
	};
	$show = function ($key, $name) {
		return ['key' => $key, 'label' => 'Показывать секцию', 'name' => $name, 'type' => 'true_false', 'ui' => 1, 'default_value' => 1];
	};

	/* ---------- Главная страница ---------- */
	acf_add_local_field_group([
		'key' => 'group_vz_front',
		'title' => 'Главная страница',
		'fields' => [
			$tab('f_tab_hero', 'Первый экран'),
			$show('f_hero_show', 'show_hero'),
			$t('f_hero_title', 'Заголовок', 'hero_title'),
			['key' => 'f_hero_text', 'label' => 'Текст', 'name' => 'hero_text', 'type' => 'wysiwyg', 'media_upload' => 0, 'toolbar' => 'basic'],
			['key' => 'f_hero_image', 'label' => 'Картинка', 'name' => 'hero_image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium'],
			['key' => 'f_hero_file', 'label' => 'Файл коммерческого предложения (PDF)', 'name' => 'hero_file', 'type' => 'file', 'return_format' => 'array'],
			$t('f_hero_file_text', 'Текст кнопки скачивания', 'hero_file_text'),
			$t('f_hero_btn_text', 'Текст кнопки «Связаться»', 'hero_btn_text'),
			$t('f_hero_btn_url', 'Ссылка кнопки «Связаться»', 'hero_btn_url'),
			['key' => 'f_hero_stats', 'label' => 'Цифры под текстом', 'name' => 'hero_stats', 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Добавить', 'sub_fields' => [
				$t('f_hs_value', 'Значение', 'value'),
				$t('f_hs_label', 'Подпись', 'label'),
			]],

			$tab('f_tab_gallery', 'Галерея'),
			$show('f_gallery_show', 'show_gallery'),
			$t('f_gallery_title', 'Заголовок', 'gallery_title'),
			['key' => 'f_gallery', 'label' => 'Фотографии', 'name' => 'gallery', 'type' => 'gallery', 'return_format' => 'array', 'preview_size' => 'medium', 'insert' => 'append'],

			$tab('f_tab_about', 'О компании'),
			$show('f_about_show', 'show_about'),
			$t('f_about_title', 'Заголовок', 'about_title'),
			['key' => 'f_about_image', 'label' => 'Картинка', 'name' => 'about_image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium'],
			['key' => 'f_about_items', 'label' => 'Блоки', 'name' => 'about_items', 'type' => 'repeater', 'layout' => 'block', 'button_label' => 'Добавить блок', 'sub_fields' => [
				$t('f_ai_icon', 'Иконка (эмодзи)', 'icon'),
				['key' => 'f_ai_icon_img', 'label' => 'Иконка-картинка (вместо эмодзи)', 'name' => 'icon_image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail'],
				$t('f_ai_title', 'Заголовок', 'title'),
				['key' => 'f_ai_text', 'label' => 'Текст', 'name' => 'text', 'type' => 'textarea', 'rows' => 5],
			]],

			$tab('f_tab_segments', 'Для кого'),
			$show('f_segments_show', 'show_segments'),
			$t('f_segments_title', 'Заголовок', 'segments_title'),
			$t('f_segments_btn_text', 'Текст кнопки в карточках', 'segments_btn_text'),
			['key' => 'f_segments', 'label' => 'Карточки', 'name' => 'segments', 'type' => 'repeater', 'layout' => 'block', 'button_label' => 'Добавить карточку', 'sub_fields' => [
				$t('f_sg_title', 'Заголовок', 'title'),
				['key' => 'f_sg_text', 'label' => 'Текст', 'name' => 'text', 'type' => 'textarea', 'rows' => 4],
				$t('f_sg_url', 'Ссылка кнопки', 'btn_url', ['default_value' => '#sorov']),
			]],

			$tab('f_tab_blog', 'Блог'),
			$show('f_blog_show', 'show_blog'),
			$t('f_blog_title', 'Заголовок', 'blog_title'),
			['key' => 'f_blog_count', 'label' => 'Сколько записей показывать', 'name' => 'blog_count', 'type' => 'number', 'min' => 1, 'max' => 12],
			$t('f_blog_more', 'Текст ссылки «Далее»', 'blog_more'),

			$tab('f_tab_contact', 'Контакты и форма'),
			$show('f_contact_show', 'show_contact'),
			$t('f_contact_title', 'Заголовок', 'contact_title'),
			$t('f_contact_address_label', 'Подпись «Адрес»', 'contact_address_label'),
			$t('f_contact_phone_label', 'Подпись «Контакты»', 'contact_phone_label'),
			$t('f_form_title', 'Заголовок формы', 'form_title'),
			$t('f_form_btn', 'Текст кнопки формы', 'form_btn'),
		],
		'location' => [[['param' => 'page_type', 'operator' => '==', 'value' => 'front_page']]],
		'menu_order' => 0,
		'style' => 'seamless',
		'label_placement' => 'top',
	]);

	/* ---------- Настройки сайта ---------- */
	acf_add_local_field_group([
		'key' => 'group_vz_options',
		'title' => 'Настройки сайта',
		'fields' => [
			$tab('o_tab_brand', 'Бренд'),
			['key' => 'o_logo', 'label' => 'Логотип (шапка)', 'name' => 'logo', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium'],
			['key' => 'o_logo_light', 'label' => 'Логотип (футер, светлый)', 'name' => 'logo_light', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium'],
			['key' => 'o_color_primary', 'label' => 'Основной цвет', 'name' => 'color_primary', 'type' => 'color_picker', 'default_value' => '#1f4fd8'],
			['key' => 'o_color_accent', 'label' => 'Акцентный цвет', 'name' => 'color_accent', 'type' => 'color_picker', 'default_value' => '#ff8a4c'],
			['key' => 'o_color_dark', 'label' => 'Тёмный цвет (футер, заголовки)', 'name' => 'color_dark', 'type' => 'color_picker', 'default_value' => '#0f1b3d'],

			$tab('o_tab_header', 'Шапка'),
			$t('o_header_cta_text', 'Текст кнопки', 'header_cta_text'),
			$t('o_header_cta_url', 'Ссылка кнопки', 'header_cta_url'),

			$tab('o_tab_contacts', 'Контакты'),
			$t('o_phone', 'Телефон', 'phone'),
			['key' => 'o_email', 'label' => 'E-mail', 'name' => 'email', 'type' => 'email'],
			$t('o_address', 'Адрес', 'address'),
			['key' => 'o_map_url', 'label' => 'Ссылка на карту', 'name' => 'map_url', 'type' => 'url'],
			['key' => 'o_socials', 'label' => 'Соцсети', 'name' => 'socials', 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Добавить', 'sub_fields' => [
				$t('o_so_name', 'Название', 'name'),
				['key' => 'o_so_url', 'label' => 'Ссылка', 'name' => 'url', 'type' => 'url'],
			]],

			$tab('o_tab_forms', 'Формы'),
			['key' => 'o_form_to', 'label' => 'Куда отправлять заявки (e-mail)', 'name' => 'form_recipient', 'type' => 'email', 'instructions' => 'Если пусто — на e-mail администратора WordPress.'],
			$t('o_subscribe_title', 'Кнопка подписки в футере', 'subscribe_title'),
			['key' => 'o_form_ok', 'label' => 'Сообщение после отправки', 'name' => 'form_success', 'type' => 'text', 'default_value' => 'Спасибо! Мы свяжемся с вами в ближайшее время.'],

			$tab('o_tab_footer', 'Футер'),
			$t('o_copyright', 'Копирайт', 'copyright'),
			$t('o_dev_text', 'Текст «Разработка»', 'dev_text'),
			['key' => 'o_dev_url', 'label' => 'Ссылка «Разработка»', 'name' => 'dev_url', 'type' => 'url'],

			$tab('o_tab_code', 'Код / аналитика'),
			['key' => 'o_head_code', 'label' => 'Код в <head> (метрика, пиксели)', 'name' => 'head_code', 'type' => 'textarea', 'rows' => 4],
			['key' => 'o_body_code', 'label' => 'Код перед </body>', 'name' => 'body_code', 'type' => 'textarea', 'rows' => 4],
		],
		'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'vesmir-settings']]],
	]);
});

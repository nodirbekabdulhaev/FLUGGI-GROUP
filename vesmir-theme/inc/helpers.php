<?php
defined('ABSPATH') || exit;

/** Значение поля главной страницы (ACF) или дефолт. */
function vz_f($name) {
	$d = vz_defaults();
	$v = null;
	if (function_exists('get_field')) {
		$id = (int) get_option('page_on_front') ?: get_queried_object_id();
		$v = get_field($name, $id ?: false);
	}
	if ($v === null || $v === '' || $v === false || $v === []) {
		return $d[$name] ?? null;
	}
	return $v;
}

/** Значение глобальной опции (страница «Настройки сайта») или дефолт. */
function vz_o($name) {
	$d = vz_defaults();
	$v = function_exists('get_field') ? get_field($name, 'option') : null;
	if ($v === null || $v === '' || $v === false || $v === []) {
		return $d[$name] ?? null;
	}
	return $v;
}

/** Включена ли секция (ACF-переключатель, по умолчанию включена). */
function vz_show($name) {
	if (!function_exists('get_field')) return true;
	$id = (int) get_option('page_on_front') ?: get_queried_object_id();
	$v = get_field($name, $id ?: false);
	return $v === null || $v === '' ? true : (bool) $v;
}

/** URL картинки из значения ACF (массив / ID / строка). */
function vz_img($v, $size = 'large') {
	if (is_array($v)) return $v['sizes'][$size] ?? $v['url'] ?? '';
	if (is_numeric($v)) return wp_get_attachment_image_url((int) $v, $size) ?: '';
	return (string) $v;
}

function vz_img_full($v) {
	return is_array($v) ? ($v['url'] ?? '') : vz_img($v, 'full');
}

/** URL файла из значения ACF. */
function vz_file($v) {
	if (is_array($v)) return $v['url'] ?? '';
	if (is_numeric($v)) return wp_get_attachment_url((int) $v) ?: '';
	return (string) $v;
}

/** Ссылка из ACF-поля типа link или строки. */
function vz_link($v, $fallback = '#') {
	if (is_array($v)) return $v['url'] ?? $fallback;
	return $v ?: $fallback;
}

/** Ссылка tel: из номера. */
function vz_tel($phone) {
	return 'tel:' . preg_replace('/[^\d+]/', '', (string) $phone);
}

/** Якорные ссылки ведут на главную с любой страницы. */
function vz_anchor($hash) {
	return (is_front_page() ? '' : home_url('/')) . $hash;
}

<?php
defined('ABSPATH') || exit;
$logo = function_exists('get_field') ? get_field('logo', 'option') : null;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#content">Перейти к содержимому</a>

<header class="header" id="top">
	<div class="container header__in">
		<a class="logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php bloginfo('name'); ?>">
			<?php if ($logo) : ?>
				<img src="<?php echo esc_url(vz_img($logo, 'medium')); ?>" alt="<?php bloginfo('name'); ?>" height="40">
			<?php elseif (has_custom_logo()) : echo wp_get_attachment_image(get_theme_mod('custom_logo'), 'medium'); ?>
			<?php else : ?>
				<span class="logo__text"><?php bloginfo('name'); ?></span>
			<?php endif; ?>
		</a>

		<nav class="nav" id="nav" aria-label="Главное меню">
			<?php vz_menu('primary'); ?>
			<a class="btn btn--sm nav__cta" href="<?php echo esc_url(vz_o('header_cta_url')); ?>"><?php echo esc_html(vz_o('header_cta_text')); ?></a>
		</nav>

		<button class="burger" aria-controls="nav" aria-expanded="false" aria-label="Меню"><span></span><span></span><span></span></button>
	</div>
</header>

<main id="content">

<?php
defined('ABSPATH') || exit;
get_header();

foreach (['hero', 'gallery', 'about', 'segments', 'blog', 'contact'] as $section) {
	if (vz_show('show_' . $section)) {
		get_template_part('template-parts/section', $section);
	}
}

get_footer();

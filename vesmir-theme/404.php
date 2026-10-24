<?php
defined('ABSPATH') || exit;
get_header(); ?>
<section class="section page">
	<div class="container container--narrow">
		<h1 class="section__title">404 — страница не найдена</h1>
		<p><a class="btn" href="<?php echo esc_url(home_url('/')); ?>">На главную</a></p>
	</div>
</section>
<?php get_footer();

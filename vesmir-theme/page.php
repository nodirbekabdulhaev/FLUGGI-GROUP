<?php
defined('ABSPATH') || exit;
get_header(); while (have_posts()) : the_post(); ?>
<article class="section page">
	<div class="container container--narrow">
		<h1 class="section__title"><?php the_title(); ?></h1>
		<div class="prose"><?php the_content(); ?></div>
	</div>
</article>
<?php endwhile; get_footer();

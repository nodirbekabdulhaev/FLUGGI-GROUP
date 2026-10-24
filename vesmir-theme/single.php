<?php
defined('ABSPATH') || exit;
get_header(); while (have_posts()) : the_post(); ?>
<article class="section page">
	<div class="container container--narrow">
		<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time>
		<h1 class="section__title"><?php the_title(); ?></h1>
		<?php if (has_post_thumbnail()) the_post_thumbnail('large', ['class' => 'page__cover']); ?>
		<div class="prose"><?php the_content(); ?></div>
	</div>
</article>
<?php endwhile; get_footer();

<?php defined('ABSPATH') || exit;
$q = new WP_Query(['posts_per_page' => (int) vz_f('blog_count') ?: 3, 'ignore_sticky_posts' => true]);
if (!$q->have_posts()) return;
?>
<section class="section section--tint" id="blog">
	<div class="container">
		<h2 class="section__title"><?php echo esc_html(vz_f('blog_title')); ?></h2>
		<div class="posts">
			<?php while ($q->have_posts()) : $q->the_post(); get_template_part('template-parts/post-card'); endwhile; wp_reset_postdata(); ?>
		</div>
	</div>
</section>

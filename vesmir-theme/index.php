<?php
defined('ABSPATH') || exit;
get_header(); ?>
<section class="section page">
	<div class="container">
		<h1 class="section__title"><?php echo is_home() ? 'Наш блог' : esc_html(get_the_archive_title()); ?></h1>
		<?php if (have_posts()) : ?>
			<div class="posts">
				<?php while (have_posts()) : the_post(); get_template_part('template-parts/post-card'); endwhile; ?>
			</div>
			<div class="pagination"><?php the_posts_pagination(['prev_text' => '←', 'next_text' => '→']); ?></div>
		<?php else : ?>
			<p>Ничего не найдено.</p>
		<?php endif; ?>
	</div>
</section>
<?php get_footer();

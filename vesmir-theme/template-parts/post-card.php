<?php defined('ABSPATH') || exit; $cat = get_the_category(); ?>
<article class="post">
	<?php if (has_post_thumbnail()) : ?>
		<a class="post__img" href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium_large', ['loading' => 'lazy']); ?></a>
	<?php endif; ?>
	<div class="post__body">
		<?php if ($cat) : ?><span class="post__cat"><?php echo esc_html($cat[0]->name); ?></span><?php endif; ?>
		<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time>
		<p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 24)); ?></p>
		<a class="card__link" href="<?php the_permalink(); ?>"><?php echo esc_html(vz_f('blog_more') ?: 'Далее'); ?> →</a>
	</div>
</article>

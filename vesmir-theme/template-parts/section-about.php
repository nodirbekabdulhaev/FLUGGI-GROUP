<?php defined('ABSPATH') || exit; ?>
<section class="section section--tint" id="about">
	<div class="container about">
		<?php if ($img = vz_img(vz_f('about_image'), 'large')) : ?>
			<div class="about__media"><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr(vz_f('about_title')); ?>" loading="lazy"></div>
		<?php endif; ?>
		<div class="about__body">
			<h2 class="section__title"><?php echo esc_html(vz_f('about_title')); ?></h2>
			<?php foreach ((array) vz_f('about_items') as $it) : ?>
				<article class="feature">
					<div class="feature__icon" aria-hidden="true">
						<?php if (!empty($it['icon_image'])) : ?>
							<img src="<?php echo esc_url(vz_img($it['icon_image'], 'thumbnail')); ?>" alt="">
						<?php else : echo esc_html($it['icon'] ?? ''); endif; ?>
					</div>
					<div>
						<h3><?php echo esc_html($it['title'] ?? ''); ?></h3>
						<p><?php echo esc_html($it['text'] ?? ''); ?></p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php defined('ABSPATH') || exit;
$items = vz_f('gallery');
if (!$items) return;
?>
<section class="section" id="gallery">
	<div class="container">
		<h2 class="section__title"><?php echo esc_html(vz_f('gallery_title')); ?></h2>
		<div class="gallery">
			<?php foreach ($items as $i => $img) : ?>
				<a class="gallery__item" href="<?php echo esc_url(vz_img_full($img)); ?>">
					<img src="<?php echo esc_url(vz_img($img, 'medium_large')); ?>" alt="<?php echo esc_attr(is_array($img) ? ($img['alt'] ?? '') : ''); ?>" loading="lazy" decoding="async">
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

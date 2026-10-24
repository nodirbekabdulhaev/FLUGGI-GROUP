<?php defined('ABSPATH') || exit;
$file = vz_file(vz_f('hero_file'));
?>
<section class="hero">
	<div class="container hero__in">
		<div class="hero__text">
			<h1><?php echo esc_html(vz_f('hero_title')); ?></h1>
			<div class="prose"><?php echo wp_kses_post(vz_f('hero_text')); ?></div>
			<div class="hero__actions">
				<?php if ($file) : ?>
					<a class="btn" href="<?php echo esc_url($file); ?>" target="_blank" rel="noopener"><?php echo esc_html(vz_f('hero_file_text')); ?></a>
				<?php endif; ?>
				<a class="btn btn--ghost" href="<?php echo esc_url(vz_f('hero_btn_url')); ?>"><?php echo esc_html(vz_f('hero_btn_text')); ?></a>
			</div>
			<?php if ($stats = vz_f('hero_stats')) : ?>
				<dl class="stats">
					<?php foreach ($stats as $s) : ?>
						<div><dt><?php echo esc_html($s['value']); ?></dt><dd><?php echo esc_html($s['label']); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</div>
		<?php if ($img = vz_img(vz_f('hero_image'), 'large')) : ?>
			<div class="hero__media"><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr(vz_f('hero_title')); ?>" fetchpriority="high"></div>
		<?php endif; ?>
	</div>
</section>

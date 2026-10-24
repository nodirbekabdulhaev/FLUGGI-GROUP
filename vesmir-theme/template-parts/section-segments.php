<?php defined('ABSPATH') || exit; ?>
<section class="section" id="segment">
	<div class="container">
		<h2 class="section__title"><?php echo esc_html(vz_f('segments_title')); ?></h2>
		<div class="cards">
			<?php foreach ((array) vz_f('segments') as $n => $c) : ?>
				<article class="card">
					<span class="card__num"><?php echo esc_html(sprintf('%02d', $n + 1)); ?></span>
					<h3><?php echo esc_html($c['title'] ?? ''); ?></h3>
					<p><?php echo esc_html($c['text'] ?? ''); ?></p>
					<a class="card__link" href="<?php echo esc_url($c['btn_url'] ?? '#sorov'); ?>"><?php echo esc_html(vz_f('segments_btn_text')); ?> →</a>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>

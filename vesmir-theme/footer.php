<?php
defined('ABSPATH') || exit;
$logo  = function_exists('get_field') ? get_field('logo_light', 'option') : null;
$phone = vz_o('phone');
$email = vz_o('email');
?>
</main>

<footer class="footer">
	<div class="container footer__grid">
		<div class="footer__brand">
			<a class="logo logo--light" href="<?php echo esc_url(home_url('/')); ?>">
				<?php if ($logo) : ?>
					<img src="<?php echo esc_url(vz_img($logo, 'medium')); ?>" alt="<?php bloginfo('name'); ?>" height="40">
				<?php else : ?>
					<span class="logo__text"><?php bloginfo('name'); ?></span>
				<?php endif; ?>
			</a>
			<nav aria-label="Меню в футере"><?php vz_menu('footer', 'footer__menu'); ?></nav>
			<?php if ($s = vz_o('socials')) : ?>
				<ul class="socials">
					<?php foreach ($s as $item) : if (empty($item['name'])) continue; ?>
						<li><a href="<?php echo esc_url($item['url'] ?? '#'); ?>" target="_blank" rel="noopener"><?php echo esc_html($item['name']); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="footer__contacts">
			<h4><?php echo esc_html(vz_f('contact_title') ?: 'Свяжитесь с нами:'); ?></h4>
			<ul>
				<?php if ($email) : ?><li><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></li><?php endif; ?>
				<?php if ($phone) : ?><li><a href="<?php echo esc_attr(vz_tel($phone)); ?>"><?php echo esc_html($phone); ?></a></li><?php endif; ?>
				<?php if ($a = vz_o('address')) : ?><li><a href="<?php echo esc_url(vz_o('map_url')); ?>" target="_blank" rel="noopener"><?php echo esc_html($a); ?> →</a></li><?php endif; ?>
			</ul>
		</div>

		<form class="subscribe" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="vz_lead">
			<input type="hidden" name="vz_type" value="subscribe">
			<?php wp_nonce_field('vz_lead', 'vz_nonce'); ?>
			<input class="hp" type="text" name="vz_website" tabindex="-1" autocomplete="off" aria-hidden="true">
			<label class="sr" for="sub-email">Email</label>
			<input id="sub-email" type="email" name="email" placeholder="Email" required>
			<button class="btn btn--accent" type="submit"><?php echo esc_html(vz_o('subscribe_title')); ?></button>
		</form>
	</div>

	<div class="container footer__bottom">
		<p><?php echo esc_html(vz_o('copyright')); ?></p>
		<p><a href="<?php echo esc_url(vz_o('dev_url')); ?>" target="_blank" rel="noopener"><?php echo esc_html(vz_o('dev_text')); ?></a></p>
	</div>
</footer>

<div class="lightbox" hidden><button class="lightbox__close" aria-label="Закрыть">×</button><img alt=""></div>
<?php wp_footer(); ?>
</body>
</html>

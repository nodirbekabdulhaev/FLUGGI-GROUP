<?php defined('ABSPATH') || exit;
$phone = vz_o('phone'); $email = vz_o('email');
?>
<section class="section contact" id="sorov">
	<div class="container contact__in">
		<div class="contact__info">
			<h2 class="section__title"><?php echo esc_html(vz_f('contact_title')); ?></h2>
			<h3><?php echo esc_html(vz_f('contact_address_label')); ?></h3>
			<p><a href="<?php echo esc_url(vz_o('map_url')); ?>" target="_blank" rel="noopener"><?php echo esc_html(vz_o('address')); ?></a></p>
			<h3><?php echo esc_html(vz_f('contact_phone_label')); ?></h3>
			<p>
				<a href="<?php echo esc_attr(vz_tel($phone)); ?>"><?php echo esc_html($phone); ?></a><br>
				<a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
			</p>
		</div>

		<form class="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<h3><?php echo esc_html(vz_f('form_title')); ?></h3>
			<?php vz_form_notice(); ?>
			<input type="hidden" name="action" value="vz_lead">
			<?php wp_nonce_field('vz_lead', 'vz_nonce'); ?>
			<input class="hp" type="text" name="vz_website" tabindex="-1" autocomplete="off" aria-hidden="true">
			<label>Имя<input type="text" name="name" autocomplete="name" required></label>
			<label>Телефон<input type="tel" name="phone" autocomplete="tel" required></label>
			<label>Email<input type="email" name="email" autocomplete="email"></label>
			<label>Сообщение<textarea name="message" rows="4"></textarea></label>
			<button class="btn" type="submit"><?php echo esc_html(vz_f('form_btn')); ?></button>
		</form>
	</div>
</section>

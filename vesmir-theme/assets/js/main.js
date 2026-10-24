(function () {
	var d = document, root = d.documentElement;
	root.classList.add('js');

	var header = d.querySelector('.header');
	var onScroll = function () { header && header.classList.toggle('is-stuck', window.scrollY > 8); };
	onScroll();
	window.addEventListener('scroll', onScroll, { passive: true });

	var burger = d.querySelector('.burger'), nav = d.getElementById('nav');
	if (burger && nav) {
		var set = function (open) {
			nav.classList.toggle('is-open', open);
			burger.setAttribute('aria-expanded', open ? 'true' : 'false');
		};
		burger.addEventListener('click', function () { set(!nav.classList.contains('is-open')); });
		nav.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
	}

	// Лайтбокс галереи
	var box = d.querySelector('.lightbox');
	if (box) {
		var img = box.querySelector('img');
		var close = function () { box.hidden = true; img.removeAttribute('src'); };
		d.addEventListener('click', function (e) {
			var a = e.target.closest('.gallery__item');
			if (a) { e.preventDefault(); img.src = a.href; box.hidden = false; return; }
			if (!box.hidden && e.target !== img) close();
		});
		d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) close(); });
	}

	// Плавное появление блоков
	var targets = d.querySelectorAll('.section__title, .card, .feature, .post, .form, .hero__text, .hero__media');
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
		}, { rootMargin: '0px 0px -8% 0px' });
		targets.forEach(function (t) { t.classList.add('reveal'); io.observe(t); });
	}
})();

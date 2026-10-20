/* Fluggi CRM — встраивание формы заявки на сайт (WordPress и др.).
   <div data-fluggi-form="КЛЮЧ"></div><script src="https://ваш-crm/embed.js" async></script> */
(function () {
  var script = document.currentScript;
  var base = script ? new URL(script.src).origin : '';
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
  function mount() {
    var nodes = document.querySelectorAll('[data-fluggi-form]');
    for (var i = 0; i < nodes.length; i++) {
      (function (el) {
        if (el.getAttribute('data-fluggi-ready')) return;
        el.setAttribute('data-fluggi-ready', '1');
        var key = el.getAttribute('data-fluggi-form');
        var q = new URLSearchParams(window.location.search);
        var p = new URLSearchParams();
        UTM.forEach(function (k) {
          if (q.get(k)) p.set(k, q.get(k));
        });
        p.set('page', window.location.href);
        var f = document.createElement('iframe');
        f.src = base + '/f/' + encodeURIComponent(key) + '?' + p.toString();
        f.title = 'Заявка';
        f.loading = 'lazy';
        f.style.cssText = 'width:100%;max-width:560px;border:0;min-height:460px;display:block;';
        el.appendChild(f);
        window.addEventListener('message', function (e) {
          if (e.origin !== base || !e.data || e.data.fluggiForm !== key || !e.data.height) return;
          f.style.height = e.data.height + 'px';
          f.style.minHeight = '0';
        });
      })(nodes[i]);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();

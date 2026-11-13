<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $form->title }}</title>
    <style>
        *{box-sizing:border-box}html,body{margin:0;background:#fff;color:#18181b;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .w{max-width:32rem;margin:0 auto;padding:16px}h1{font-size:20px;margin:0 0 4px}p.d{margin:0 0 8px;color:#52525b;font-size:14px}
        form{display:grid;gap:12px}label{display:grid;gap:4px;font-size:14px}label span{font-weight:500}
        input,textarea,select{width:100%;border:1px solid #d4d4d8;border-radius:8px;padding:10px 12px;font-size:15px;color:#18181b;background:#fff;outline:none}
        input:focus,textarea:focus,select:focus{border-color:#2a78d6;box-shadow:0 0 0 3px rgba(42,120,214,.2)}
        [aria-invalid=true]{border-color:#ef4444}.e{color:#dc2626;font-size:12px}.req{color:#ef4444}
        button{margin-top:4px;border:0;border-radius:8px;background:#2a78d6;color:#fff;font-weight:600;font-size:15px;padding:12px 16px;cursor:pointer}
        button:disabled{opacity:.6}.trap{position:absolute;left:-9999px;width:0;height:0;opacity:0}.ok{padding:32px 0;text-align:center}.ok b{font-size:28px}
    </style>
</head>
<body>
<div class="w" id="root">
    <form id="ff" method="POST" action="{{ route('hooks.intake', $form->key) }}" novalidate>
        <div><h1>{{ $form->title }}</h1>@if ($form->description)<p class="d">{{ $form->description }}</p>@endif</div>
        @foreach ($form->fields ?? [] as $f)
            <label for="ff-{{ $f['key'] }}">
                <span>{{ $f['label'] }}@if (! empty($f['required']))<b class="req"> *</b>@endif</span>
                @if ($f['type'] === 'textarea')
                    <textarea id="ff-{{ $f['key'] }}" name="{{ $f['key'] }}" rows="3" @required(! empty($f['required']))></textarea>
                @elseif ($f['type'] === 'select')
                    <select id="ff-{{ $f['key'] }}" name="{{ $f['key'] }}" @required(! empty($f['required']))><option value="">—</option>@foreach ($f['options'] ?? [] as $o)<option>{{ $o }}</option>@endforeach</select>
                @else
                    <input id="ff-{{ $f['key'] }}" name="{{ $f['key'] }}" type="{{ $f['type'] === 'phone' ? 'tel' : ($f['type'] === 'email' ? 'email' : 'text') }}"
                           @if ($f['type'] === 'phone') inputmode="tel" autocomplete="tel" placeholder="+998 90 123-45-67" @elseif ($f['type'] === 'email') autocomplete="email" @elseif ($f['key'] === 'name') autocomplete="name" @endif
                           @required(! empty($f['required']))>
                @endif
                <span class="e" data-error="{{ $f['key'] }}"></span>
            </label>
        @endforeach
        {{-- Ловушка для ботов: человек это поле не видит --}}
        <input class="trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <p class="e" id="failure" role="alert"></p>
        <button type="submit" id="send">{{ $form->button_text }}</button>
    </form>
</div>
<script>
(function () {
    var key = @js($form->key), renderedAt = Date.now(), form = document.getElementById('ff'), root = document.getElementById('root');
    var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    var T = @js(['sending' => t('integrations.public.sending'), 'failed' => t('integrations.public.failed'), 'tooMany' => t('integrations.public.tooMany'), 'offline' => t('integrations.public.offline')]);
    // Сайт подстраивает высоту iframe под форму
    function post() { if (window.parent !== window) window.parent.postMessage({ fluggiForm: key, height: Math.ceil(root.getBoundingClientRect().height) + 8 }, '*'); }
    if (window.ResizeObserver) new ResizeObserver(post).observe(root); post();
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('send'), label = btn.textContent, q = new URLSearchParams(location.search), data = {}, utm = {};
        document.querySelectorAll('[data-error]').forEach(function (el) { el.textContent = ''; });
        document.getElementById('failure').textContent = '';
        form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
        Array.prototype.forEach.call(form.elements, function (el) { if (el.name && el.name !== 'website' && el.value) data[el.name] = el.value; });
        UTM.forEach(function (k) { if (q.get(k)) utm[k] = q.get(k); });
        btn.disabled = true; btn.textContent = T.sending;
        fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ data: data, utm: utm, page: q.get('page') || document.referrer || undefined, website: form.website.value || undefined, renderedAt: renderedAt }) })
            .then(function (res) { return res.json().catch(function () { return null; }).then(function (body) { return { res: res, body: body }; }); })
            .then(function (r) {
                if (!r.res.ok) {
                    var errs = (r.body && r.body.errors) || {};
                    Object.keys(errs).forEach(function (k) {
                        var f = k.replace(/^data\./, ''), el = document.querySelector('[data-error="' + f + '"]');
                        if (el) el.textContent = errs[k][0];
                        var input = document.getElementById('ff-' + f); if (input) input.setAttribute('aria-invalid', 'true');
                    });
                    document.getElementById('failure').textContent = r.res.status === 429 ? T.tooMany : ((r.body && r.body.message) || T.failed);
                    return;
                }
                root.innerHTML = '<div class="ok" role="status"><b>✓</b><p></p></div>';
                root.querySelector('p').textContent = (r.body && r.body.message) || '';
                post();
            })
            .catch(function () { document.getElementById('failure').textContent = T.offline; })
            .finally(function () { btn.disabled = false; btn.textContent = label; post(); });
    });
})();
</script>
</body>
</html>

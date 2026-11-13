<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="robots" content="noindex"><title>{{ $message }}</title></head>
<body style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:0 16px;text-align:center">
    <h2>{{ $message }}</h2>
    @foreach ($errors as $e)<p style="color:#dc2626">{{ $e }}</p>@endforeach
    @if ($back && preg_match('#^https?://#', $back))<p><a href="{{ $back }}">{{ t('integrations.public.backToSite') }}</a></p>@endif
</body>
</html>

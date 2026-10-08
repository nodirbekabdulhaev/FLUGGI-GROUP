<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
</head>
<body class="flex min-h-screen items-center justify-center px-4">
    <div class="text-center">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">F</div>
        <div class="text-5xl font-semibold tracking-tight text-slate-300">@yield('code')</div>
        <div class="mt-2 text-lg font-medium">@yield('message')</div>
        <div class="mt-6 flex justify-center gap-2">
            <a href="javascript:history.back()" class="btn-secondary">{{ __('Назад') }}</a>
            <a href="{{ url('/') }}" class="btn-primary">{{ __('На главную') }}</a>
        </div>
    </div>
</body>
</html>

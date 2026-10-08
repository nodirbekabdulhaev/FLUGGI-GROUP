<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FLUGGI EDU ERP')</title>
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
</head>
<body class="min-h-screen">
<div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">F</div>
        <h1 class="text-xl font-semibold">FLUGGI EDU ERP</h1>
    </div>
    <div class="card card-body">@yield('content')</div>
    <p class="mt-4 text-center text-xs text-slate-500">
        <a href="{{ route('locale', 'ru') }}" class="link">Русский</a> · <a href="{{ route('locale', 'uz') }}" class="link">O‘zbekcha</a>
    </p>
</div>
</body>
</html>

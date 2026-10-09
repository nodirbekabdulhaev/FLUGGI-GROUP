<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#18181b">
<meta name="robots" content="noindex, nofollow">
<title>{{ trim(($__env->yieldContent('title') ? $__env->yieldContent('title').' · ' : '').t('app.name')) }}</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
@vite(['resources/css/app.css', 'resources/js/app.js'])

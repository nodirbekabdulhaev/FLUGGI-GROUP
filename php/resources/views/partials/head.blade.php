<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#2f6c86">
<meta name="robots" content="noindex, nofollow">
<title>{{ trim(($__env->yieldContent('title') ? $__env->yieldContent('title').' · ' : '').t('app.name')) }}</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/icons/favicon-32.png?v=2" type="image/png" sizes="32x32">
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png?v=2">
@vite(['resources/css/app.css', 'resources/js/app.js'])

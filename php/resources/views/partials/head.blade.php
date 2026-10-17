@php($brand = brand())
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="{{ $brand->themeColor() }}">
<meta name="robots" content="noindex, nofollow">
<title>{{ trim(($__env->yieldContent('title') ? $__env->yieldContent('title').' · ' : '').$brand->name()) }}</title>
<link rel="manifest" href="/app-manifest?v={{ $brand->stamp() }}">
<link rel="icon" href="{{ $brand->icon('favicon-32') }}" type="image/png" sizes="32x32">
@unless ($brand->custom())
    <link rel="icon" href="/favicon.ico?v={{ \App\Support\Brand::DEFAULT_VERSION }}" sizes="any">
@endunless
<link rel="apple-touch-icon" href="{{ $brand->icon('apple-touch-icon') }}">
<meta name="apple-mobile-web-app-title" content="{{ $brand->shortName() }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])

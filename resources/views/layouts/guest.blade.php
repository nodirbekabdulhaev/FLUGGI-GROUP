<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('partials.head')
</head>
<body class="flex min-h-dvh items-center justify-center bg-background p-4">
<main class="w-full max-w-sm">
    @yield('content')
</main>
@include('partials.toasts')
</body>
</html>

@extends('layouts.guest')
@section('title', t('common.offline'))
@section('content')
    <div class="mb-6 flex flex-col items-center text-center">
        <img src="{{ brand()->logo() }}" alt="" class="mb-3 size-14 rounded-xl">
        <p class="text-xl font-semibold">{{ brand()->name() }}</p>
    </div>
    <x-empty icon="globe" :title="t('common.offline')" class="card" />
    <div class="mt-4 flex justify-center">
        <button type="button" class="btn btn-primary" onclick="location.reload()">{{ t('common.retry') }}</button>
    </div>
    <script>
        // Связь вернулась — открываем страницу снова; пока нет — пробуем каждые 10 секунд
        window.addEventListener('online', () => location.reload());
        setInterval(() => { if (navigator.onLine) location.reload(); }, 10000);
    </script>
@endsection

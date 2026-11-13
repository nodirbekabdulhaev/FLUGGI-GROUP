{{-- Вкладка «Проекты» в карточке сделки: проект сделки, если пользователь может его видеть --}}
@php($dealProject = \App\Services\Projects\ProjectService::forDeal($deal->id))
@include('projects.partials.mini-list', ['projects' => collect($dealProject ? [$dealProject] : [])])

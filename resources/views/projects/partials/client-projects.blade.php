{{-- Вкладка «Проекты» в карточке клиента --}}
@include('projects.partials.mini-list', ['projects' => \App\Services\Projects\ProjectService::forClient($client->id)])

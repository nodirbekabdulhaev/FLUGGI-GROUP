{{-- LTV и здоровье клиента в карточке клиента: @include('analytics.partials.client-insight', ['client' => $client]) --}}
@include('analytics.client-insight', ['clientId' => $client->id])

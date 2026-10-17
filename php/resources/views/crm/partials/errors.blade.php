{{-- Ошибки проверки формы (видны и когда окно формы закрыто) --}}
@if ($errors->any())
    <div class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert">
        <p class="font-medium">{{ t('crm.common.formErrors') }}</p>
        <ul class="mt-1 list-disc pl-5">@foreach (array_unique($errors->all()) as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

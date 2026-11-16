{{-- Смена ответственного: $action, $owners [id => имя], $current --}}
<x-modal name="assign-record" :title="t('crm.common.assign')">
    <form method="POST" action="{{ $action }}" class="grid gap-4">
        @csrf
        <x-select name="owner_id" :label="t('crm.fields.owner')" :options="$owners" :value="$current" required />
        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'assign-record')">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
        </div>
    </form>
</x-modal>

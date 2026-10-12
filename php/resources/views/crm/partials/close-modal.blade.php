{{-- Закрытие лида/сделки (ТЗ §7, §39): $action, $reasons --}}
@php $reasonsMeta = \App\Models\LossReason::where('is_active', true)->pluck('requires_comment', 'id'); @endphp
<x-modal name="close-record" :title="t('crm.common.closeTitle')" :open="$errors->any() && old('_form') === 'close'">
    <form method="POST" action="{{ $action }}" class="grid gap-4"
          x-data="{ status: @js(old('status', 'LOST')), reason: @js(old('loss_reason_id', '')), comment: @js(old('comment', '')), meta: @js($reasonsMeta) }">
        @csrf <input type="hidden" name="_form" value="close">
        <p class="text-sm text-muted-foreground">{{ t('crm.common.closeHint') }}</p>
        <div>
            <label class="label" for="close-status">{{ t('crm.fields.status') }}</label>
            <select id="close-status" name="status" class="input" x-model="status">
                @foreach (\App\Services\Crm\Crm::CLOSE_STATUSES as $s)<option value="{{ $s }}">{{ t('crm.closeStatus.'.$s) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label" for="close-reason">{{ t('crm.fields.lossReason') }}<span class="text-danger" x-show="['LOST','REJECTED'].includes(status)"> *</span></label>
            <select id="close-reason" name="loss_reason_id" class="input" x-model="reason">
                <option value="">{{ t('crm.common.select') }}</option>
                @foreach ($reasons as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            @error('loss_reason_id')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="close-comment">{{ t('crm.fields.comment') }}<span class="text-danger" x-show="meta[reason]"> *</span></label>
            <textarea id="close-comment" name="comment" rows="3" class="input h-auto py-2" maxlength="2000" x-model="comment"></textarea>
            @error('comment')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'close-record')">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn btn-danger" :disabled="(['LOST','REJECTED'].includes(status) && !reason) || (meta[reason] && !comment.trim())">{{ t('crm.common.close') }}</button>
        </div>
    </form>
</x-modal>

<?php

namespace App\Services\Integrations;

use App\Exceptions\BusinessRule;
use App\Models\FormSubmission;
use App\Models\LeadForm;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Формы для сайта: CEO собирает форму в CRM, вставляет код на сайт (WordPress), заявки
 * становятся лидами (источник «Сайт»), повторные — отмечаются в найденном лиде.
 */
final class FormService
{
    public const FIELD_TYPES = ['text', 'phone', 'email', 'textarea', 'select'];

    /** Ключи полей, которые ложатся в поля лида. */
    public const LEAD_KEYS = ['name', 'phone', 'email', 'company', 'message', 'instagram'];

    /** Слишком быстрая отправка после показа формы — признак бота. */
    public const MIN_FILL_MS = 1500;

    public const DEFAULT_FIELDS = [
        ['key' => 'name', 'label' => 'Ваше имя', 'type' => 'text', 'required' => true],
        ['key' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => true],
        ['key' => 'company', 'label' => 'Компания', 'type' => 'text', 'required' => false],
        ['key' => 'message', 'label' => 'Что вас интересует?', 'type' => 'textarea', 'required' => false],
    ];

    public static function list(): array
    {
        $forms = LeadForm::with('owner:id,full_name')->orderBy('created_at')->get();
        $stats = FormSubmission::whereIn('form_id', $forms->pluck('id'))->groupBy('form_id')
            ->selectRaw("form_id, COUNT(*) AS total, SUM(result = 'LEAD_CREATED') AS leads, MAX(created_at) AS last")->get()->keyBy('form_id');

        return $forms->map(fn ($f) => [
            'form' => $f,
            'submissions' => (int) ($stats[$f->id]->total ?? 0),
            'leads' => (int) ($stats[$f->id]->leads ?? 0),
            'last' => $stats[$f->id]->last ?? null,
        ])->all();
    }

    /** Поля формы: проверка ключей и наличия телефона или email. */
    public static function normalizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $type = $f['type'];
            $item = ['key' => trim($f['key']), 'label' => trim($f['label']), 'type' => $type, 'required' => (bool) ($f['required'] ?? false)];
            if ($type === 'select') {
                $opts = is_array($f['options'] ?? null) ? $f['options'] : explode(';', (string) ($f['options'] ?? ''));
                $item['options'] = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 120), $opts), fn ($o) => $o !== ''));
            }
            $out[] = $item;
        }
        $keys = array_column($out, 'key');
        if (count(array_unique($keys)) !== count($keys)) {
            throw new BusinessRule(t('integrations.errors.duplicateKeys'), ['fields' => t('integrations.errors.duplicateKeys')]);
        }
        if (! array_filter($out, fn ($f) => in_array($f['type'], ['phone', 'email'], true))) {
            throw new BusinessRule(t('integrations.errors.needContact'), ['fields' => t('integrations.errors.needContact')]);
        }

        return $out;
    }

    public static function save(?string $id, array $input): LeadForm
    {
        $data = [
            'name' => $input['name'],
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'button_text' => $input['button_text'] ?: 'Отправить',
            'success_message' => $input['success_message'] ?: 'Спасибо! Мы свяжемся с вами в ближайшее время.',
            'fields' => self::normalizeFields($input['fields']),
            'service_id' => $input['service_id'] ?? null,
            'source_id' => $input['source_id'] ?? null,
            'owner_id' => $input['owner_id'] ?? null,
            'team_id' => $input['team_id'] ?? null,
            'is_active' => (bool) ($input['is_active'] ?? false),
        ];
        $form = $id ? LeadForm::findOrFail($id) : null;

        return DB::transaction(function () use ($form, $data) {
            if ($form) {
                $form->update($data);
            } else {
                $form = LeadForm::create($data + ['key' => rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=')]);
            }
            Audit::log($form->wasRecentlyCreated ? 'lead_form.create' : 'lead_form.update', 'lead_form', $form->id, [
                'name' => ['old' => null, 'new' => $form->name], 'isActive' => ['old' => null, 'new' => $form->is_active],
            ]);

            return $form;
        });
    }

    public static function remove(string $id): void
    {
        $f = LeadForm::findOrFail($id);
        DB::transaction(function () use ($f) {
            $f->update(['is_active' => false]);
            $f->delete();
            Audit::log('lead_form.delete', 'lead_form', $f->id, ['name' => ['old' => $f->name, 'new' => null]]);
        });
    }

    public static function submissions(string $id)
    {
        LeadForm::findOrFail($id);

        return FormSubmission::with('lead:id,number,title')->where('form_id', $id)->orderByDesc('created_at')->limit(100)->get();
    }

    public static function active(string $key): LeadForm
    {
        return LeadForm::where('key', $key)->where('is_active', true)->firstOrFail();
    }

    /**
     * Заявка с сайта. Боты (скрытое поле, слишком быстрая отправка) получают «успех»,
     * но лид не создаётся.
     *
     * @param  array{data:array,utm?:?array,page?:?string,website?:?string,renderedAt?:int|float|null}  $input
     */
    public static function submit(string $key, array $input, ?string $ip): string
    {
        $form = self::active($key);
        $fields = $form->fields ?? [];
        $data = [];
        $errors = [];
        foreach ($fields as $f) {
            $v = trim((string) ($input['data'][$f['key']] ?? ''));
            $path = 'data.'.$f['key'];
            if (! empty($f['required']) && $v === '') {
                $errors[$path] = t('integrations.public.fill', ['label' => $f['label']]);

                continue;
            }
            if ($v !== '' && $f['type'] === 'phone' && (strlen(preg_replace('/\D/', '', $v)) < 7 || mb_strlen($v) > 30)) {
                $errors[$path] = t('integrations.public.badPhone');
            } elseif ($v !== '' && $f['type'] === 'email' && ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v)) {
                $errors[$path] = t('integrations.public.badEmail');
            } elseif ($v !== '' && $f['type'] === 'select' && ! empty($f['options']) && ! in_array($v, $f['options'], true)) {
                $errors[$path] = t('integrations.public.pickOption');
            }
            if ($v !== '') {
                $data[$f['key']] = mb_substr($v, 0, 4000);
            }
        }
        if ($errors) {
            throw new BusinessRule(t('integrations.public.fixErrors'), $errors);
        }
        $utm = ! empty($input['utm']) ? array_map(fn ($v) => mb_substr((string) $v, 0, 300), array_slice($input['utm'], 0, 10, true)) : null;
        $page = isset($input['page']) ? mb_substr((string) $input['page'], 0, 1000) : null;
        $record = fn (string $result, ?string $leadId) => FormSubmission::create([
            'form_id' => $form->id, 'lead_id' => $leadId, 'data' => $data, 'utm' => $utm, 'page' => $page, 'ip' => $ip, 'result' => $result,
        ]);

        $rendered = $input['renderedAt'] ?? null;
        $tooFast = is_numeric($rendered) && (microtime(true) * 1000 - (float) $rendered) < self::MIN_FILL_MS;
        if (! empty($input['website']) || $tooFast) {
            $record('SPAM', null);

            return $form->success_message;
        }

        $byType = function (string $type, string $k) use ($data, $fields) {
            if (isset($data[$k])) {
                return $data[$k];
            }
            foreach ($fields as $f) {
                if ($f['type'] === $type && isset($data[$f['key']])) {
                    return $data[$f['key']];
                }
            }

            return null;
        };
        $name = $data['name'] ?? null;
        $company = $data['company'] ?? null;
        $extra = [];
        foreach ($fields as $f) {
            if (! in_array($f['key'], self::LEAD_KEYS, true) && isset($data[$f['key']]) && ! in_array($f['type'], ['phone', 'email'], true)) {
                $extra[] = $f['label'].': '.$data[$f['key']];
            }
        }
        $utmLine = $utm ? implode(', ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($utm), $utm)) : null;
        $comment = implode("\n", array_filter([
            $data['message'] ?? null,
            ...$extra,
            'Форма: '.$form->name,
            $page ? 'Страница: '.mb_substr($page, 0, 300) : null,
            $utmLine ? 'UTM: '.$utmLine : null,
        ]));

        $res = IntakeService::intake([
            'title' => ($company ?? $name ?? 'Заявка с сайта').' — '.$form->name,
            'contactName' => $name,
            'companyName' => $company,
            'phone' => $byType('phone', 'phone'),
            'email' => $byType('email', 'email'),
            'instagram' => $data['instagram'] ?? null,
            'comment' => $comment,
            'sourceCode' => 'WEBSITE',
            'sourceId' => $form->source_id,
            'serviceId' => $form->service_id,
            'ownerId' => $form->owner_id,
            'teamId' => $form->team_id,
            'channel' => 'Форма «'.$form->name.'»',
        ]);
        $record($res['duplicate'] ? 'DUPLICATE' : 'LEAD_CREATED', $res['leadId']);

        return $form->success_message;
    }
}

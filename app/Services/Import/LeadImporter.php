<?php

namespace App\Services\Import;

use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Service;
use App\Models\SmmIdea;
use App\Services\Crm\Crm;
use App\Services\Crm\LeadService;
use App\Services\References\ExchangeRates;
use App\Services\Smm\SmmIdeaService;
use App\Support\Format;

/**
 * Лиды: создаются через LeadService::create — те же проверки (услуга, источник, ролик SMM),
 * распределение ответственного, скоринг, этап «Новый», таймлайн, аудит и событие lead.created.
 * Дубли (телефон по 9 цифрам, Instagram, email) среди видимых лидов и клиентов и внутри файла
 * пропускаются, если не отмечено «Импортировать дубли тоже».
 */
final class LeadImporter extends Importer
{
    public static function entity(): string
    {
        return 'leads';
    }

    public static function permission(): string
    {
        return 'lead.create';
    }

    public static function backUrl(): string
    {
        return '/sales/leads';
    }

    protected function columns(): array
    {
        return [
            'title' => [],
            'contact_name' => [],
            'company' => [],
            'phone' => ['type' => 'phone'],
            'email' => ['type' => 'email'],
            'telegram' => [],
            'instagram' => [],
            'website' => [],
            'city' => [],
            'source' => ['required' => true],
            'service' => ['required' => true],
            'budget' => ['type' => 'money'],
            'currency' => [],
            'priority' => [],
            'desired_date' => ['type' => 'date'],
            'owner_email' => ['type' => 'email'],
            'video' => [],
            'comment' => [],
        ];
    }

    public function options(): array
    {
        return ['duplicates'];
    }

    public function rules(): array
    {
        return [t('import.rules.leadName'), t('import.rules.leadContact'), t('import.rules.leadDuplicates'), t('import.rules.leadVideo'), t('import.rules.owner')];
    }

    protected function allowsDuplicates(ImportContext $ctx): bool
    {
        return $ctx->option('duplicates');
    }

    protected function example(): array
    {
        return [
            'title' => '', 'contact_name' => t('import.example.contactName'), 'company' => t('import.example.company'),
            'phone' => '+998 90 123-45-67', 'email' => 'info@example.uz', 'telegram' => '@example', 'instagram' => '@example.uz',
            'website' => 'example.uz', 'city' => t('import.example.city'), 'source' => 'INSTAGRAM', 'service' => 'SMM',
            'budget' => '9000000', 'currency' => 'UZS', 'priority' => 'MEDIUM', 'desired_date' => Format::today()->addDays(14)->toDateString(),
            'owner_email' => '', 'video' => '', 'comment' => t('import.example.comment'),
        ];
    }

    protected function reference(): array
    {
        return [
            $this->col('source') => self::refRows(LeadSource::where('is_active', true)->orderBy('sort')->get()),
            $this->col('service') => self::refRows(Service::where('is_active', true)->orderBy('sort')->get()),
            $this->col('currency') => array_map(fn ($c) => [$c, $c], Crm::CURRENCIES),
            $this->col('priority') => self::enumRows(Crm::PRIORITIES, 'crm.priority'),
            $this->col('owner_email') => self::assignable('lead.create'),
            $this->col('video') => array_map(fn (SmmIdea $i) => [Format::code('V', $i->number), $i->title],
                SmmIdea::where('status', 'PUBLISHED')->orderByDesc('published_at')->limit(300)->get()->all()),
            t('import.template.lossReasons') => self::refRows(LossReason::where('is_active', true)->orderBy('sort')->get()),
        ];
    }

    protected function label(array $v): string
    {
        return (string) ($v['title'] ?? $v['company'] ?? $v['contact_name'] ?? $v['phone'] ?? '');
    }

    protected function check(array $v, ImportContext $ctx): array
    {
        $data = [
            'title' => Values::text($v['title'], 200, $this->col('title')),
            'contact_name' => Values::text($v['contact_name'], 120, $this->col('contact_name')),
            'company_name' => Values::text($v['company'], 160, $this->col('company')),
            'phone' => Values::phone($v['phone'], $this->col('phone')),
            'email' => Values::email($v['email'], $this->col('email')),
            'telegram' => Values::text($v['telegram'], 64, $this->col('telegram')),
            'instagram' => Values::text($v['instagram'], 64, $this->col('instagram')),
            'website' => Values::text($v['website'], 200, $this->col('website')),
            'city' => Values::text($v['city'], 80, $this->col('city')),
            'budget' => Values::money($v['budget'], $this->col('budget')),
            'currency' => Values::enum($v['currency'], Crm::CURRENCIES, 'import.currency', $this->col('currency'), ['сум' => 'UZS', 'so\'m' => 'UZS', 'sum' => 'UZS', '$' => 'USD', 'доллар' => 'USD', 'dollar' => 'USD']) ?? 'UZS',
            'priority' => Values::enum($v['priority'], Crm::PRIORITIES, 'crm.priority', $this->col('priority')) ?? 'MEDIUM',
            'desired_date' => Values::date($v['desired_date'], $this->col('desired_date')),
            'comment' => Values::text($v['comment'], 4000, $this->col('comment')),
        ];
        // Минимум полей (ТЗ §78) — как в форме: имя или компания, телефон или Telegram
        if (! $data['contact_name'] && ! $data['company_name']) {
            throw new RowError(t('leads.errors.nameOrCompany'));
        }
        if (! $data['phone'] && ! $data['telegram']) {
            throw new RowError(t('leads.errors.phoneOrTelegram'));
        }
        $source = Values::ref($v['source'], $this->sources($ctx), $this->col('source'));
        $service = Values::ref($v['service'], $this->services($ctx), $this->col('service'));
        $data['source_id'] = $source->id;
        $data['service_id'] = $service->id;
        $data['smm_idea_id'] = null;
        if ($v['video'] !== null || $source->code === SmmIdeaService::LEAD_SOURCE) {
            $number = Values::number($v['video'], 'V');
            $ideaId = $number !== null ? SmmIdea::where('number', $number)->value('id') : null;
            if ($v['video'] !== null && ! $ideaId) {
                throw new RowError(t('import.errors.videoNotFound', ['value' => $v['video']]));
            }
            // Источник «Видео SMM» — обязателен опубликованный ролик (иначе причина отказа)
            $data['smm_idea_id'] = SmmIdeaService::videoFor($source->code, $ideaId);
        }
        if ($data['budget'] !== null) {
            ExchangeRates::convert($data['budget'], $data['currency']); // нет курса USD — ошибка строки
        }
        $owner = $this->owner($v['owner_email'], 'lead.create');
        $data['owner_id'] = $owner?->id;

        $keys = [
            ($p = Crm::phoneKey($data['phone'])) ? 'phone:'.$p : null,
            ($ig = Crm::igKey($data['instagram'])) ? 'ig:'.$ig : null,
            ($em = Crm::emailKey($data['email'])) ? 'email:'.$em : null,
        ];
        $dup = LeadService::duplicates($data['phone'], $data['instagram'], $data['email']);
        $found = [
            ...$dup['leads']->map(fn ($l) => Format::code('L', $l->number).' «'.$l->title.'»')->all(),
            ...$dup['clients']->map(fn ($c) => Format::code('C', $c->number).' «'.$c->name.'»')->all(),
        ];
        if ($found) {
            $list = implode(', ', array_slice($found, 0, 3));
            if (! $ctx->option('duplicates')) {
                return ['status' => 'duplicate', 'reason' => t('import.reasons.duplicateOf', ['list' => $list])];
            }

            return ['status' => 'create', 'reason' => t('import.reasons.duplicateImported', ['list' => $list]), 'data' => $data, 'keys' => $keys];
        }

        return ['status' => 'create', 'data' => $data, 'keys' => $keys];
    }

    protected function create(array $data, ImportContext $ctx): string
    {
        $lead = app(LeadService::class)->create($data);

        return Format::code('L', $lead->number);
    }
}

<?php

namespace App\Services\Integrations;

use App\Domain\LeadScore;
use App\Exceptions\BusinessRule;
use App\Models\Activity;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\OutboxEvent;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Crm\Activities;
use App\Services\Crm\CrmAccess;
use App\Services\Notifications\Notifier;
use App\Services\References\ExchangeRates;
use App\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * Приём заявок из внешних каналов (форма сайта, Instagram, таргет): поиск дубля среди
 * открытых лидов по телефону / Instagram, выбор ответственного, создание лида по правилам CRM
 * (номер, этап NEW, скоринг, активность, событие lead.created).
 */
final class IntakeService
{
    public const SETTINGS_KEY = 'integrations';

    public const DEFAULT_KEYWORDS = ['цена', 'стоимость', 'сколько', 'прайс', 'нарх', 'qancha', 'price', 'директ'];

    /** Настройки интеграций (секреты Meta — только в .env). */
    public static function settings(): array
    {
        $v = Setting::find(self::SETTINGS_KEY)?->value;
        $v = is_array($v) ? $v : [];

        return [
            'ownerId' => $v['ownerId'] ?? null,
            'teamId' => $v['teamId'] ?? null,
            'autoLeadFromDirect' => (bool) ($v['autoLeadFromDirect'] ?? true),
            'autoLeadFromComments' => in_array($v['autoLeadFromComments'] ?? null, ['off', 'keywords', 'all'], true) ? $v['autoLeadFromComments'] : 'keywords',
            'commentKeywords' => array_values(array_filter((array) ($v['commentKeywords'] ?? self::DEFAULT_KEYWORDS), 'is_string')),
            'serviceId' => $v['serviceId'] ?? null,
        ];
    }

    public static function saveSettings(array $value, string $userId): array
    {
        Setting::updateOrCreate(['key' => self::SETTINGS_KEY], ['value' => $value, 'updated_by_id' => $userId]);

        return self::settings();
    }

    /** Телефон для сравнения: последние 9 цифр (+998 90 123-45-67 → 901234567). */
    public static function phoneKey(?string $v): ?string
    {
        $d = preg_replace('/\D/', '', (string) $v);

        return strlen($d) >= 7 ? substr($d, -9) : null;
    }

    /** Instagram для сравнения: без @, ссылки и регистра. */
    public static function igKey(?string $v): ?string
    {
        $s = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', trim((string) $v));
        $s = preg_replace('#/.*$#', '', ltrim($s, '@'));
        $s = mb_strtolower($s);

        return $s !== '' ? $s : null;
    }

    /**
     * Ответственный: указанный менеджер/РОП, иначе менеджер отдела с наименьшим числом открытых
     * лидов (в отделе без менеджеров — его РОП), иначе любой менеджер/РОП; никого нет — CEO.
     */
    public static function pickOwner(?string $ownerId, ?string $teamId): string
    {
        if ($ownerId) {
            $u = User::where('status', 'ACTIVE')->whereKey($ownerId)->whereHas('role', fn ($q) => $q->whereIn('code', ['MANAGER', 'ROP']))->value('id');
            if ($u) {
                return $u;
            }
        }
        if ($teamId) {
            $inTeam = User::where('status', 'ACTIVE')->where('team_id', $teamId)->whereHas('role', fn ($q) => $q->where('code', 'MANAGER'))->exists();
            if (! $inTeam && ($head = Team::whereKey($teamId)->value('head_id'))) {
                return $head;
            }
        }
        try {
            return CrmAccess::leastLoadedOwner($teamId);
        } catch (BusinessRule) {
            // Нет ни одного менеджера и РОП — заявка не теряется: CEO
            return User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', 'CEO'))->orderBy('created_at')->firstOrFail()->id;
        }
    }

    /** Открытый лид с тем же телефоном или Instagram. */
    public static function findDuplicate(?string $phone, ?string $instagram): ?Lead
    {
        $p = self::phoneKey($phone);
        $ig = self::igKey($instagram);
        if (! $p && ! $ig) {
            return null;
        }
        // Сравнение в БД по цифрам: «+998 90 123-45-67» и «901234567» — один номер
        $digits = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), 9)";
        $candidates = Lead::where('status', 'OPEN')->where(function ($q) use ($p, $ig, $digits) {
            if ($p) {
                $q->orWhereRaw($digits.' = ?', [$p]);
            }
            if ($ig) {
                $q->orWhereRaw("LOWER(TRIM(LEADING '@' FROM COALESCE(instagram, ''))) = ?", [$ig]);
            }
        })->orderByDesc('created_at')->limit(20)->get();

        return $candidates->first(fn ($l) => ($p && self::phoneKey($l->phone) === $p) || ($ig && self::igKey($l->instagram) === $ig));
    }

    /**
     * Новый лид или отметка «повторная заявка» в найденном.
     *
     * @param  array{title:string,contactName?:?string,companyName?:?string,phone?:?string,email?:?string,instagram?:?string,comment?:?string,sourceCode:string,sourceId?:?string,serviceId?:?string,ownerId?:?string,teamId?:?string,channel:string}  $in
     * @return array{leadId:string,duplicate:bool}
     */
    public static function intake(array $in): array
    {
        $dup = self::findDuplicate($in['phone'] ?? null, $in['instagram'] ?? null);
        if ($dup) {
            DB::transaction(fn () => Activity::create(['type' => 'lead.inbound_repeat', 'actor_id' => null, 'lead_id' => $dup->id,
                'payload' => ['channel' => $in['channel'], 'text' => isset($in['comment']) ? mb_substr((string) $in['comment'], 0, 1000) : null]]));
            $comment = ! empty($in['comment']) ? ' — '.mb_substr($in['comment'], 0, 200) : '';
            Notifier::notify([$dup->owner_id], 'lead.inbound_repeat', fn () => [t('integrations.notify.repeat'), $dup->title.': '.$in['channel'].$comment], '/sales/leads/'.$dup->id);

            return ['leadId' => $dup->id, 'duplicate' => true];
        }
        $ownerId = self::pickOwner($in['ownerId'] ?? null, $in['teamId'] ?? null);
        $lead = self::createInbound($in + ['ownerId' => $ownerId], $ownerId);

        return ['leadId' => $lead->id, 'duplicate' => false];
    }

    /** Лид из внешнего канала — по правилам создания лида в CRM; автор — ответственный, актор события — система. */
    public static function createInbound(array $in, string $ownerId): Lead
    {
        $source = (! empty($in['sourceId']) ? LeadSource::find($in['sourceId']) : null)
            ?? LeadSource::where('code', $in['sourceCode'])->first()
            ?? LeadSource::where('code', 'OTHER')->firstOrFail();
        $stage = DealStage::where('code', 'NEW')->firstOrFail();
        $owner = User::findOrFail($ownerId);
        $serviceId = ! empty($in['serviceId']) && Service::whereKey($in['serviceId'])->exists() ? $in['serviceId'] : null;

        return DB::transaction(function () use ($in, $source, $stage, $owner, $serviceId) {
            $lead = Lead::create([
                'title' => mb_substr($in['title'], 0, 200),
                'contact_name' => $in['contactName'] ?? null,
                'company_name' => $in['companyName'] ?? null,
                'phone' => $in['phone'] ?? null,
                'email' => $in['email'] ?? null,
                'instagram' => $in['instagram'] ?? null,
                'comment' => $in['comment'] ?? null,
                'source_id' => $source->id,
                'service_id' => $serviceId,
                'owner_id' => $owner->id,
                'team_id' => $owner->team_id,
                'stage_id' => $stage->id,
                'created_by_id' => $owner->id,
            ]);
            $lead->refresh();
            self::rescore($lead);
            Activities::stageChange($lead->id, null, null, $stage->id, $owner->id);
            Activity::create(['type' => 'lead.created', 'actor_id' => null, 'lead_id' => $lead->id,
                'payload' => ['owner' => $owner->full_name, 'source' => $source->name_ru, 'channel' => $in['channel']]]);
            OutboxEvent::create(['type' => 'lead.created', 'actor_id' => null, 'available_at' => now(), 'payload' => [
                'leadId' => $lead->id, 'ownerId' => $owner->id, 'teamId' => $owner->team_id, 'createdById' => $owner->id, 'budgetUzs' => null,
            ]]);

            return $lead;
        });
    }

    /** Скоринг лида (ТЗ §10) — как при создании лида в CRM. */
    private static function rescore(Lead $lead): void
    {
        $min = null;
        $service = $lead->service_id ? Service::find($lead->service_id) : null;
        if ($service?->min_price) {
            try {
                $rate = ExchangeRates::rateFor($service->currency);
                $min = (float) (string) Money::toUzs((string) $service->min_price, $service->currency, $rate);
            } catch (BusinessRule) {
                $min = null;
            }
        }
        $stages = ['NEW', 'CONTACTED', 'QUALIFICATION', 'MEETING_SCHEDULED', 'MEETING_DONE'];
        $r = LeadScore::compute([
            'budgetUzs' => $lead->budget_uzs !== null ? (float) (string) $lead->budget_uzs : null,
            'serviceMinPriceUzs' => $min,
            'hasService' => (bool) $lead->service_id,
            'priority' => $lead->priority ?? 'MEDIUM',
            'daysToDesiredDate' => null,
            'companySize' => $lead->company_size,
            'interest' => $lead->interest,
            'stageIndex' => 0,
            'stageCount' => count($stages),
        ]);
        if ($r['score'] !== (int) $lead->score || $r['level'] !== $lead->score_level) {
            $lead->update(['score' => $r['score'], 'score_level' => $r['level']]);
        }
    }
}

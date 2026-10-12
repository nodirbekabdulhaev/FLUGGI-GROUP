<?php

namespace App\Services\Crm;

use App\Models\DealStage;
use App\Models\Direction;
use App\Models\ExchangeRate;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Service;
use App\Support\Audit;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Справочники CRM: услуги, направления, источники, причины потерь, этапы, курс USD. */
final class ReferenceService
{
    public const KINDS = ['sources' => LeadSource::class, 'loss-reasons' => LossReason::class];

    public function saveService(?Service $before, array $input): Service
    {
        $data = [
            'code' => $input['code'],
            'name_ru' => $input['name'],
            'name_uz' => $input['name_uz'] ?? null,
            'name_en' => $input['name_en'] ?? null,
            'description' => $input['description'] ?? null,
            'base_price' => $input['base_price'] ?? null,
            'min_price' => $input['min_price'] ?? null,
            'currency' => $input['currency'] ?? 'UZS',
            'pricing_type' => $input['pricing_type'] ?? 'FIXED',
            'direction_id' => $input['direction_id'] ?? null,
            'is_active' => (bool) ($input['is_active'] ?? false),
            'sort' => (int) ($input['sort'] ?? 0),
        ];

        return DB::transaction(function () use ($before, $data) {
            $old = $before?->getAttributes();
            $saved = $before ? tap($before)->update($data)->fresh() : Service::create($data);
            Audit::log($before ? 'service.update' : 'service.create', 'service', $saved->id, $before
                ? Audit::diff($old, $saved->getAttributes(), ['name_ru', 'base_price', 'min_price', 'currency', 'pricing_type', 'is_active'])
                : ['name' => ['old' => null, 'new' => $saved->name_ru]]);

            return $saved;
        });
    }

    public function saveItem(string $kind, ?string $id, array $input): LeadSource|LossReason
    {
        $model = self::KINDS[$kind] ?? abort(404);
        $data = [
            'code' => $input['code'],
            'name_ru' => $input['name'],
            'name_uz' => $input['name_uz'] ?? null,
            'name_en' => $input['name_en'] ?? null,
            'is_active' => (bool) ($input['is_active'] ?? false),
            'sort' => (int) ($input['sort'] ?? 0),
        ];
        if ($kind === 'loss-reasons') {
            $data['requires_comment'] = (bool) ($input['requires_comment'] ?? false);
        }

        return DB::transaction(function () use ($model, $kind, $id, $data) {
            $saved = $id ? tap($model::findOrFail($id))->update($data) : $model::create($data);
            Audit::log($kind.'.'.($id ? 'update' : 'create'), $kind, $saved->id, [
                'name' => ['old' => null, 'new' => $saved->name_ru],
                'isActive' => ['old' => null, 'new' => $saved->is_active],
            ]);

            return $saved;
        });
    }

    public function updateStage(DealStage $stage, array $input): DealStage
    {
        $data = array_filter([
            'name_ru' => $input['name'] ?? null,
            'name_uz' => $input['name_uz'] ?? null,
            'name_en' => $input['name_en'] ?? null,
            'probability' => $input['probability'] ?? null,
            'color' => $input['color'] ?? null,
        ], fn ($v) => $v !== null);

        return DB::transaction(function () use ($stage, $data) {
            $old = $stage->getAttributes();
            $stage->update($data);
            $after = $stage->fresh();
            $changes = Audit::diff($old, $after->getAttributes(), ['name_ru', 'name_uz', 'name_en', 'probability', 'color']);
            if ($changes) {
                Audit::log('stage.update', 'stage', $stage->id, $changes);
            }

            return $after;
        });
    }

    /** Курс на дату (по умолчанию — сегодня по Ташкенту); на одну дату — одна запись. */
    public function setRate(string $rate, ?string $date): ExchangeRate
    {
        $date ??= Format::today()->toDateString();

        return DB::transaction(function () use ($rate, $date) {
            $before = ExchangeRate::where('currency', 'USD')->whereDate('date', $date)->first();
            $old = $before ? (string) $before->rate_to_uzs : null;
            if ($before) {
                $before->update(['rate_to_uzs' => $rate, 'set_by_id' => access()->id()]);
                $saved = $before->fresh();
            } else {
                $saved = ExchangeRate::create(['date' => $date, 'currency' => 'USD', 'rate_to_uzs' => $rate, 'set_by_id' => access()->id()]);
            }
            Audit::log('exchange_rate.set', 'exchange_rate', $saved->id, ["USD $date" => ['old' => $old, 'new' => (string) $saved->rate_to_uzs]]);

            return $saved;
        });
    }

    /** Направление бизнеса: создать или переименовать (код генерируется). */
    public function saveDirection(?Direction $before, array $input): Direction
    {
        $data = ['name' => $input['name'], 'sort' => (int) ($input['sort'] ?? 100), 'is_active' => (bool) ($input['is_active'] ?? false)];

        return DB::transaction(function () use ($before, $data) {
            $oldName = $before?->name;
            $d = $before ? tap($before)->update($data) : Direction::create($data + ['code' => 'D_'.Str::upper(bin2hex(random_bytes(4)))]);
            Audit::log($before ? 'direction.update' : 'direction.create', 'direction', $d->id, ['name' => ['old' => $oldName, 'new' => $d->name]]);

            return $d;
        });
    }
}

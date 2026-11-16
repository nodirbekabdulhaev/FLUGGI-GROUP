<?php

namespace App\Services\Sales;

use App\Domain\Finance;
use App\Domain\People;
use App\Models\EmployeeRate;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\TariffItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Тарифы услуг с себестоимостью, единицы работ и личные ставки сотрудников
 * (ТЗ: тарификация, ценообразование). Экономика тарифа видна только с правом на финансы компании.
 */
final class CatalogService
{
    public const SPECIALTIES = ['SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE'];

    public const CURRENCIES = ['UZS', 'USD'];

    // ─────────────── Единицы работ ───────────────

    public static function workItems(): Collection
    {
        return WorkItem::orderBy('sort')->orderBy('name')->get();
    }

    public static function saveWorkItem(?string $id, array $input): WorkItem
    {
        $data = [
            'name' => $input['name'],
            'unit' => $input['unit'] ?: 'шт',
            'specialty' => $input['specialty'] ?? null,
            'default_rate' => $input['default_rate'],
            'currency' => $input['currency'],
            'is_active' => (bool) $input['is_active'],
        ];

        return DB::transaction(function () use ($id, $data) {
            $w = $id
                ? tap(WorkItem::findOrFail($id))->update($data)
                : WorkItem::create($data + ['code' => 'W_'.strtoupper(bin2hex(random_bytes(4))), 'sort' => 100]);
            $w->refresh();
            Audit::log($id ? 'work_item.update' : 'work_item.create', 'work_item', $w->id, ['defaultRate' => ['old' => null, 'new' => (string) $w->default_rate]]);

            return $w;
        });
    }

    // ─────────────── Личные ставки ───────────────

    /** Ставки сотрудника: CEO (зарплаты) — любого, сотрудник — свои. */
    public static function employeeRates(string $userId): Collection
    {
        if ($userId !== access()->id() && ! access()->can('payroll.manage', 'ALL')) {
            abort(403);
        }
        $rates = EmployeeRate::where('user_id', $userId)->get()->keyBy('work_item_id');

        return WorkItem::where('is_active', true)->orderBy('sort')->orderBy('name')->get()->map(fn (WorkItem $w) => (object) [
            'workItem' => $w,
            'rate' => isset($rates[$w->id]) ? (string) $rates[$w->id]->rate : null,
            'currency' => $rates[$w->id]->currency ?? null,
        ]);
    }

    /** @param  list<array{work_item_id:string, rate:?string, currency:string}>  $rates */
    public static function saveEmployeeRates(string $userId, array $rates): void
    {
        $user = User::find($userId) ?? abort(404);
        DB::transaction(function () use ($user, $rates) {
            foreach ($rates as $r) {
                if ($r['rate'] === null || $r['rate'] === '') {
                    DB::table('employee_rates')->where('user_id', $user->id)->where('work_item_id', $r['work_item_id'])->delete();
                } else {
                    DB::table('employee_rates')->upsert(
                        [['user_id' => $user->id, 'work_item_id' => $r['work_item_id'], 'rate' => $r['rate'], 'currency' => $r['currency'], 'updated_at' => now()->format('Y-m-d H:i:s.v')]],
                        ['user_id', 'work_item_id'],
                        ['rate', 'currency', 'updated_at'],
                    );
                }
            }
            Audit::log('employee.rates', 'user', $user->id, ['rates' => ['old' => null, 'new' => array_map(fn ($r) => [
                'workItemId' => $r['work_item_id'], 'rate' => $r['rate'] === '' ? null : $r['rate'], 'currency' => $r['currency'],
            ], $rates)]]);
        });
    }

    // ─────────────── Тарифы ───────────────

    public static function usdRate(): string
    {
        try {
            return ExchangeRates::rateFor('USD');
        } catch (Throwable) {
            return '0';
        }
    }

    /**
     * Оценка накладных на один проект в месяц — по последнему полному месяцу (для экономики тарифа):
     * накладные месяца ÷ (делитель из настроек финансов или число проектов в работе).
     */
    public static function overheadEstimate(): string
    {
        $month = Format::today()->startOfMonth()->subMonth()->format('Y-m');
        ['from' => $from, 'to' => $to] = People::monthRange($month);
        $overhead = (string) (DB::table('expenses as e')
            ->join('finance_categories as c', 'c.code', '=', 'e.category')
            ->whereNull('e.deleted_at')->where('e.scope', 'COMPANY')->where('c.is_overhead', true)
            ->where('e.expense_date', '>=', "$month-01")->where('e.expense_date', '<', self::nextMonthStart($month))
            ->sum('e.amount_uzs') ?: '0');
        $active = DB::table('projects')->whereNull('deleted_at')->where('status', '<>', 'CANCELLED')
            ->where('created_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $from))
            ->count();
        $divisor = Settings::get('finance')['overheadDivisor'] ?? null;

        return Finance::overheadShare($overhead, $active ?: 1, $divisor ? (int) $divisor : null);
    }

    /** @return Collection<int, Tariff> */
    public static function tariffs(bool $all = false, ?string $serviceId = null): Collection
    {
        $rows = Tariff::with(['service', 'items' => fn ($q) => $q->orderBy('sort'), 'items.workItem'])
            ->when($serviceId, fn ($q, $v) => $q->where('service_id', $v))
            ->when(! $all, fn ($q) => $q->where('is_active', true))
            ->get()
            ->sortBy([fn ($a, $b) => ($a->service->sort ?? 0) <=> ($b->service->sort ?? 0), ['sort', 'asc'], ['name', 'asc']])
            ->values();
        $ctx = ['usd' => self::usdRate(), 'overhead' => access()->can('finance.company.read', 'ALL') ? self::overheadEstimate() : '0'];
        foreach ($rows as $t) {
            self::decorate($t, $ctx);
        }

        return $rows;
    }

    /** Себестоимость позиций и экономика тарифа по базовым ставкам и текущему курсу. */
    public static function decorate(Tariff $t, ?array $ctx = null): Tariff
    {
        $ctx ??= ['usd' => self::usdRate(), 'overhead' => access()->can('finance.company.read', 'ALL') ? self::overheadEstimate() : '0'];
        $toUzs = fn (BigDecimal $amount, string $currency) => $currency === 'USD' ? $amount->multipliedBy($ctx['usd']) : $amount;
        $company = access()->can('finance.company.read', 'ALL');
        $executors = BigDecimal::zero();
        foreach ($t->items as $i) {
            $cost = $i->kind === 'PIECE' && $i->workItem
                ? $toUzs(BigDecimal::of((string) $i->workItem->default_rate)->multipliedBy((string) $i->quantity), $i->workItem->currency)
                : $toUzs(BigDecimal::of((string) ($i->amount ?? '0')), $i->currency);
            $executors = $executors->plus($cost);
            $i->setAttribute('cost_uzs', $company ? (string) $cost->toScale(2) : '0.00');
        }
        $economics = null;
        if ($company) {
            $priceUzs = $toUzs(BigDecimal::of((string) $t->price), $t->currency);
            $margin = $priceUzs->minus($executors)->minus($ctx['overhead']);
            $economics = [
                'priceUzs' => (string) $priceUzs->toScale(2),
                'executorsUzs' => (string) $executors->toScale(2),
                'overheadUzs' => (string) BigDecimal::of($ctx['overhead'])->toScale(2),
                'marginUzs' => (string) $margin->toScale(2),
                'marginPct' => Finance::marginPct($margin, $priceUzs),
            ];
        }
        $t->setAttribute('economics', $economics);

        return $t;
    }

    public static function saveTariff(?string $id, array $input): Tariff
    {
        Service::find($input['service_id']) ?? abort(404);
        $items = [];
        foreach (array_values($input['items'] ?? []) as $sort => $i) {
            $piece = $i['kind'] === 'PIECE';
            $items[] = [
                'kind' => $i['kind'],
                'work_item_id' => $piece ? $i['work_item_id'] : null,
                'quantity' => $piece ? (string) $i['quantity'] : '1',
                'specialty' => $piece ? null : $i['specialty'],
                'amount' => $piece ? null : $i['amount'],
                'currency' => $i['currency'] ?? 'UZS',
                'label' => ($i['label'] ?? null) ?: null,
                'sort' => $sort,
            ];
        }
        $data = [
            'service_id' => $input['service_id'],
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'price' => $input['price'],
            'currency' => $input['currency'],
            'is_active' => (bool) $input['is_active'],
            'sort' => (int) ($input['sort'] ?? 0),
        ];
        $before = $id ? (Tariff::find($id) ?? abort(404)) : null;
        $oldPrice = $before ? (string) $before->price.' '.$before->currency : null;

        $t = DB::transaction(function () use ($before, $data, $items, $oldPrice, $input) {
            if ($before) {
                // Состав заменяется целиком. Проекты хранят свои строки себестоимости (копию), ссылку снимаем.
                $itemIds = TariffItem::where('tariff_id', $before->id)->pluck('id')->all();
                if ($itemIds) {
                    DB::table('project_cost_lines')->whereIn('tariff_item_id', $itemIds)->update(['tariff_item_id' => null]);
                }
                TariffItem::where('tariff_id', $before->id)->delete();
                $before->update($data);
                $t = $before;
            } else {
                $t = Tariff::create($data);
            }
            foreach ($items as $i) {
                TariffItem::create($i + ['id' => (string) Str::uuid(), 'tariff_id' => $t->id]);
            }
            Audit::log($before ? 'tariff.update' : 'tariff.create', 'tariff', $t->id, [
                'price' => ['old' => $oldPrice, 'new' => $input['price'].' '.$input['currency']],
            ]);

            return $t;
        });

        return self::decorate(Tariff::with(['service', 'items' => fn ($q) => $q->orderBy('sort'), 'items.workItem'])->findOrFail($t->id));
    }

    /** Начало следующего месяца для DATE-колонок (календарная дата без часового пояса). */
    private static function nextMonthStart(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));

        return $m === 12 ? sprintf('%04d-01-01', $y + 1) : sprintf('%04d-%02d-01', $y, $m + 1);
    }
}

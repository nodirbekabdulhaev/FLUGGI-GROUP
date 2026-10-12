<?php

namespace App\Services\Sales;

use App\Models\Deal;
use App\Models\Contract;
use App\Models\ProposalVersion;
use App\Models\Service;
use App\Models\StoredFile;

/**
 * Данные для вкладок карточки сделки (КП, договоры, оплаты, файлы). Каждая выборка — с проверкой
 * области права по видимости сделки (CrmAccess::byDeal), как списки модуля.
 */
final class Panels
{
    public static function proposals(Deal $deal): array
    {
        $items = ProposalsService::query()->with(ProposalsService::WITH)->where('deal_id', $deal->id)->orderByDesc('updated_at')->get();
        $versions = ProposalVersion::with('author:id,full_name')->whereIn('proposal_id', $items->pluck('id'))
            ->orderByDesc('version')->get()->groupBy('proposal_id');

        return [
            'items' => $items,
            'versions' => $versions,
            'editor' => self::editor(),
        ];
    }

    /** Справочники для редактора КП: услуги, активные тарифы, курс USD. */
    public static function editor(): array
    {
        $uz = app()->getLocale() === 'uz';

        return [
            'services' => Service::orderBy('sort')->orderBy('name_ru')->get()->map(fn (Service $s) => [
                'id' => $s->id, 'name' => $uz ? ($s->name_uz ?? $s->name_ru) : $s->name_ru,
                'basePrice' => $s->base_price !== null ? (string) $s->base_price : null, 'isActive' => (bool) $s->is_active,
            ])->values()->all(),
            'tariffs' => CatalogService::tariffs()->map(fn ($t) => [
                'id' => $t->id, 'serviceId' => $t->service_id, 'serviceName' => $uz ? ($t->service->name_uz ?? $t->service->name_ru) : $t->service->name_ru,
                'name' => $t->name, 'description' => $t->description, 'price' => (string) $t->price, 'currency' => $t->currency,
            ])->values()->all(),
            'usd' => CatalogService::usdRate(),
            'canPrice' => access()->can('proposal.approve'),
        ];
    }

    public static function contracts(Deal $deal): array
    {
        $items = ContractsService::query()->with(ContractsService::WITH)->where('deal_id', $deal->id)->orderByDesc('created_at')->get();
        ContractsService::withPaid($items);
        $accepted = access()->can('proposal.read')
            ? ProposalsService::query()->where('deal_id', $deal->id)->where('status', 'ACCEPTED')->orderByDesc('accepted_at')->get()
            : collect();

        return ['items' => $items, 'accepted' => $accepted];
    }

    public static function payments(Deal $deal): array
    {
        $items = PaymentsService::query()->with(PaymentsService::WITH)->where('deal_id', $deal->id)->orderByDesc('created_at')->get();
        PaymentsService::withRefundable($items);
        // Договоры сделки для выбора в оплате (сделка уже видна пользователю)
        $contracts = Contract::where('deal_id', $deal->id)->where('status', '<>', 'CANCELLED')->orderByDesc('created_at')->get();

        return ['items' => $items, 'contracts' => $contracts, 'signed' => $contracts->firstWhere('status', 'SIGNED')];
    }

    public static function files(Deal $deal)
    {
        return StoredFile::with('uploadedBy:id,full_name')->where('deal_id', $deal->id)->orderByDesc('created_at')->get();
    }
}

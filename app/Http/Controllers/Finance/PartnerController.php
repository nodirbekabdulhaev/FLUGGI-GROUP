<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\Input;
use App\Services\Finance\PartnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Мой доход партнёра»: доля партнёра по каждому проекту, заявки на выплату и их утверждение.
 * Доступ — только партнёр и CEO (проверка в PartnerService, маршруты — auth.only).
 */
class PartnerController extends Controller
{
    public function index(): View
    {
        PartnerService::authorizeView();
        $overview = PartnerService::overview();
        $payouts = PartnerService::payouts();

        return view('finance.partner', [
            'settings' => PartnerService::settings(),
            'partner' => PartnerService::partner(),
            'rows' => $overview['rows'],
            'totals' => $overview['totals'],
            'pending' => $payouts->where('status', 'REQUESTED')->values(),
            'payouts' => $payouts,
            'canDecide' => PartnerService::canDecide(),
            'isPartner' => PartnerService::isPartner(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PartnerService::authorizeView();
        Input::normalize($request, ['amount']);
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'amount' => ['required', Input::MONEY, 'not_in:0,0.0,0.00'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        PartnerService::request($data['project_id'], $data['amount'], $data['comment'] ?? null);

        return back()->with('ok', t('partner.requested'));
    }

    public function approve(string $id): RedirectResponse
    {
        PartnerService::decide($id, true, null);

        return back()->with('ok', t('partner.approved'));
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:2000']]);
        PartnerService::decide($id, false, $data['reject_reason']);

        return back()->with('ok', t('partner.rejected'));
    }
}

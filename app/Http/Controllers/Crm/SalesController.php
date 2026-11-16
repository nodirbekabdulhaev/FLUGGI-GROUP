<?php

namespace App\Http\Controllers\Crm;

use Illuminate\Http\RedirectResponse;

/** /sales — раздел продаж: лиды, если они доступны, иначе сделки. */
class SalesController extends CrmController
{
    public function index(): RedirectResponse
    {
        return redirect(access()->can('lead.read') ? route('crm.leads.index') : route('crm.deals.index'));
    }
}

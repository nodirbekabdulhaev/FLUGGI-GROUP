<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Журнал аудита (ТЗ §45): только чтение, записи неизменяемы. */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'actor' => ['nullable', 'uuid'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $logs = AuditLog::with('actor:id,full_name')
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($f['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', \App\Support\Format::fromLocalInput($v.'T00:00')))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \App\Support\Format::fromLocalInput($v.'T00:00')->addDay()))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();

        return view('settings.audit', ['logs' => $logs, 'filters' => $f]);
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $q = AuditLog::forTenant()->with('user:id,name')->latest('id');
        foreach (['event', 'auditable_type', 'user_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        if ($request->filled('object_id')) {
            $q->where('auditable_id', (int) $request->query('object_id'));
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->query('to'));
        }

        return view('audit.index', [
            'logs' => $q->paginate(40)->withQueryString(),
            'types' => AuditLog::forTenant()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
            'users' => \App\Support\Lookup::users(),
        ]);
    }
}

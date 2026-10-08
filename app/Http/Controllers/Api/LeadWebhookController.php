<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Course;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\LeadService;
use Illuminate\Http\Request;

/** POST /api/v1/webhooks/leads — public intake (site, Instagram, ads, other CRMs). Auth: X-Webhook-Key. */
class LeadWebhookController extends Controller
{
    public function __invoke(Request $request, LeadService $leads)
    {
        $d = $request->validate([
            'name' => 'required|string|max:100', 'last_name' => 'nullable|string|max:100',
            'phone' => ['required', 'string', 'max:32', 'regex:/^[+\d\s\-()]{7,20}$/'],
            'course' => 'nullable|string|max:150', 'source' => 'nullable|string|max:100', 'branch' => 'nullable|string|max:120',
            'telegram' => 'nullable|string|max:100', 'instagram' => 'nullable|string|max:100', 'age' => 'nullable|integer|min:3|max:99',
            'comment' => 'nullable|string|max:2000', 'campaign' => 'nullable|string|max:150',
            'utm_source' => 'nullable|string|max:150', 'utm_medium' => 'nullable|string|max:150', 'utm_campaign' => 'nullable|string|max:150',
            'utm_content' => 'nullable|string|max:150', 'utm_term' => 'nullable|string|max:150',
        ]);

        // the same phone within 10 minutes = duplicate submit, not a new lead
        $phone = Lead::normalizePhone($d['phone']);
        $dup = Lead::where('phone', $phone)->where('created_at', '>=', now()->subMinutes(10))->first();
        if ($dup) {
            return response()->json(['id' => $dup->id, 'duplicate' => true], 200);
        }

        $find = fn (string $model, string $col, ?string $v) => $v ? $model::whereRaw('LOWER('.$col.') = ?', [mb_strtolower($v)])->first() : null;
        $source = $find(LeadSource::class, 'name', $d['source'] ?? null) ?? LeadSource::where('name', 'Другое')->first();
        $course = $find(Course::class, 'name', $d['course'] ?? null);
        $branch = $find(Branch::class, 'name', $d['branch'] ?? null) ?? Branch::active()->orderBy('id')->first();

        $lead = $leads->create([
            'first_name' => $d['name'], 'last_name' => $d['last_name'] ?? null, 'phone' => $d['phone'],
            'telegram' => $d['telegram'] ?? null, 'instagram' => $d['instagram'] ?? null, 'age' => $d['age'] ?? null,
            'source_id' => $source?->id, 'course_id' => $course?->id, 'branch_id' => $branch?->id,
            'comment' => trim(($d['comment'] ?? '').(($d['course'] ?? null) && ! $course ? "\nКурс (из формы): {$d['course']}" : '')) ?: null,
            'campaign' => $d['campaign'] ?? null, 'utm_source' => $d['utm_source'] ?? null, 'utm_medium' => $d['utm_medium'] ?? null,
            'utm_campaign' => $d['utm_campaign'] ?? null, 'utm_content' => $d['utm_content'] ?? null, 'utm_term' => $d['utm_term'] ?? null,
        ]);

        return response()->json(['id' => $lead->id, 'duplicate' => false], 201);
    }
}

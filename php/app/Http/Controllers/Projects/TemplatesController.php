<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → Шаблоны проектов (ТЗ §62). */
class TemplatesController extends Controller
{
    public function index(): View
    {
        $uz = app()->getLocale() === 'uz';

        return view('settings.templates', [
            'templates' => TemplateService::list(false),
            'services' => Service::orderBy('sort')->get()->mapWithKeys(fn ($s) => [$s->id => $uz ? ($s->name_uz ?? $s->name_ru) : $s->name_ru])->all(),
        ]);
    }

    private function input(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'service_id' => ['nullable', 'uuid', 'exists:services,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'tasks' => ['required', 'array', 'min:1', 'max:100'],
            'tasks.*.title' => ['required', 'string', 'max:300'],
            'tasks.*.description' => ['nullable', 'string', 'max:5000'],
            'tasks.*.role' => ['nullable', Rule::in(ProjectAccess::SPECIALTIES)],
            'tasks.*.start_offset_days' => ['required', 'integer', 'min:0', 'max:365'],
            'tasks.*.duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'tasks.*.priority' => ['required', Rule::in(ProjectAccess::PRIORITIES)],
        ], ['tasks.required' => t('templates.errors.noTasks'), 'tasks.min' => t('templates.errors.noTasks')]);
        $data['is_active'] = $request->boolean('is_active');
        $data['service_id'] ??= null;

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        TemplateService::save(null, $this->input($request));

        return back()->with('ok', t('templates.saved'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        TemplateService::save($id, $this->input($request));

        return back()->with('ok', t('templates.saved'));
    }
}

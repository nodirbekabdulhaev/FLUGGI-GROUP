<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Config-driven CRUD for simple entities (branches, rooms, courses, teachers, dictionaries …).
 * Business-critical flows (payments, enrollments, schedule, attendance …) have dedicated controllers + services.
 */
abstract class CrudController extends Controller
{
    /** @return array<string,mixed> see docs in subclasses */
    abstract protected function cfg(): array;

    protected function c(string $key, mixed $default = null): mixed
    {
        return data_get($this->cfg(), $key, $default);
    }

    protected function can(string $ability): void
    {
        $perm = $this->c('perm.'.$ability) ?? $this->c('perm.manage');
        Gate::authorize($perm);
    }

    protected function query(Request $request)
    {
        $model = $this->c('model');
        $q = $model::query();
        if ($with = $this->c('with')) {
            $q->with($with);
        }

        if ($term = trim((string) $request->query('q'))) {
            $cols = $this->c('search', []);
            $q->where(function ($w) use ($cols, $term) {
                foreach ($cols as $col) {
                    $w->orWhere($col, 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
                }
            });
        }
        foreach ($this->c('filters', []) as $f) {
            $v = $request->query($f['name']);
            if ($v !== null && $v !== '') {
                $q->where($f['column'] ?? $f['name'], $v);
            }
        }

        return $this->scope($q, $request);
    }

    protected function scope($q, Request $request)
    {
        return $q;
    }

    public function index(Request $request)
    {
        $this->can('view');
        [$col, $dir] = $this->c('order', ['id', 'desc']);
        $query = $this->query($request);
        if ($request->query('export') === 'csv' && $this->c('export')) {
            return $this->exportCsv($query->orderBy($col, $dir)->get());
        }
        $summary = method_exists($this, 'summary') ? $this->summary(clone $query) : null;
        $rows = $query->orderBy($col, $dir)->paginate(25)->withQueryString();

        return view('crud.index', ['summary' => $summary, 'cfg' => $this->cfg(), 'rows' => $rows, 'canManage' => Gate::allows($this->c('perm.manage')), 'canDelete' => Gate::allows($this->c('perm.delete') ?? $this->c('perm.manage'))]);
    }

    public function create()
    {
        $this->can('manage');

        return view('crud.form', ['cfg' => $this->cfg(), 'model' => null, 'values' => $this->defaults()]);
    }

    public function edit(string $id)
    {
        $this->can('manage');
        $model = $this->find($id);

        return view('crud.form', ['cfg' => $this->cfg(), 'model' => $model, 'values' => $this->values($model)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->can('manage');
        $data = $this->validated($request, null);
        $model = $this->persist(null, $data, $request);

        return redirect($this->afterSave($model))->with('ok', __('Сохранено'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->can('manage');
        $model = $this->find($id);
        $data = $this->validated($request, $model);
        $model = $this->persist($model, $data, $request);

        return redirect($this->afterSave($model))->with('ok', __('Сохранено'));
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->can('delete');
        $model = $this->find($id);
        if ($reason = $this->cannotDelete($model)) {
            return back()->withErrors(['delete' => $reason]);
        }
        $model->delete();

        return redirect()->route($this->c('route').'.index')->with('ok', __('Удалено'));
    }

    protected function exportCsv($rows)
    {
        $cols = collect($this->c('columns'))->reject(fn ($c) => ($c['type'] ?? '') === 'file');

        return app(\App\Services\ExportService::class)->csv($this->c('route'), $cols->pluck('label')->all(),
            $rows->map(fn ($r) => $cols->map(fn ($c) => isset($c['value']) ? strip_tags((string) $c['value']($r)) : data_get($r, $c['key'] ?? 'id'))->all()));
    }

    protected function cannotDelete(Model $model): ?string
    {
        return null;
    }

    protected function afterSave(Model $model): string
    {
        return route($this->c('route').'.index');
    }

    protected function find(string $id): Model
    {
        $model = $this->c('model');

        return $model::query()->findOrFail($id);
    }

    protected function defaults(): array
    {
        $out = [];
        foreach ($this->c('fields', []) as $f) {
            if (array_key_exists('default', $f)) {
                $out[$f['name']] = $f['default'] instanceof \Closure ? ($f['default'])() : $f['default'];
            }
        }

        return $out;
    }

    protected function values(Model $model): array
    {
        $out = [];
        foreach ($this->c('fields', []) as $f) {
            $name = $f['name'];
            if (($f['type'] ?? '') === 'multiselect') {
                $out[$name] = $model->{$f['relation'] ?? $name}()->pluck($f['key'] ?? 'id')->all();
            } elseif (($f['type'] ?? '') === 'password') {
                $out[$name] = '';
            } else {
                $v = $model->{$name} ?? null;
                if ($v instanceof \Carbon\CarbonInterface) {
                    $v = ($f['type'] ?? '') === 'datetime-local' ? $v->format('Y-m-d\TH:i') : $v->toDateString();
                }
                $out[$name] = $v;
            }
        }

        return $out;
    }

    protected function rules(?Model $model): array
    {
        $rules = [];
        foreach ($this->c('fields', []) as $f) {
            $type = $f['type'] ?? 'text';
            $r = $f['rules'] ?? ($type === 'checkbox' ? 'boolean' : 'nullable|string|max:255');
            if ($r instanceof \Closure) {
                $r = $r($model);
            }
            if ($type === 'file') {
                $r = $f['rules'] ?? 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120';
            }
            if ($type === 'multiselect') {
                $rules[$f['name'].'.*'] = 'integer';
            }
            $rules[$f['name']] = $r;
        }

        return $rules;
    }

    protected function validated(Request $request, ?Model $model): array
    {
        $data = $request->validate($this->rules($model));
        // empty password on update = keep the old one
        foreach ($this->c('fields', []) as $f) {
            if (($f['type'] ?? '') === 'password' && empty($data[$f['name']])) {
                unset($data[$f['name']]);
            }
        }

        return $data;
    }

    protected function persist(?Model $model, array $data, Request $request): Model
    {
        $pivots = [];
        $files = [];
        foreach ($this->c('fields', []) as $f) {
            $name = $f['name'];
            if (($f['type'] ?? '') === 'multiselect') {
                $pivots[$f['relation'] ?? $name] = $data[$name] ?? [];
                unset($data[$name]);
            }
            if (($f['type'] ?? '') === 'file') {
                unset($data[$name]);
                if ($request->hasFile($name)) {
                    $files[$name] = $f;
                }
            }
        }

        $data = $this->beforeSave($data, $model);
        $class = $this->c('model');
        $model = $model ?? new $class;
        $model->fill($data)->save();

        foreach ($files as $name => $f) {
            $this->storeFile($model, $request->file($name), $f);
        }
        foreach ($pivots as $rel => $ids) {
            $model->{$rel}()->sync($ids);
        }
        $this->afterPersist($model, $data, $request);

        return $model;
    }

    protected function beforeSave(array $data, ?Model $model): array
    {
        return $data;
    }

    protected function afterPersist(Model $model, array $data, Request $request): void {}

    /** Private (non-public) storage; returned through authorised download routes only. */
    protected function storeFile(Model $model, $file, array $f): void
    {
        $column = $f['column'] ?? $f['name'];
        \App\Support\Upload::delete($model->{$column});
        $path = \App\Support\Upload::store($file, $f['folder'] ?? 'uploads', $f['name']);
        $model->forceFill([$column => $path])->save();
    }
}

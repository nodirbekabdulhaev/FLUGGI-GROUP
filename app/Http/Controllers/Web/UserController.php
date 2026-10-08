<?php

namespace App\Http\Controllers\Web;

use App\Models\Role;
use App\Models\User;
use App\Support\Lookup;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends CrudController
{
    /** Roles the current user may hand out: never more power than they have themselves. */
    protected function assignableRoles(): array
    {
        $me = auth()->user();

        return Role::available()->with('permissions:id,slug')->orderBy('id')->get()
            ->filter(function (Role $r) use ($me) {
                if ($me->isSuperAdmin()) {
                    return true;
                }

                return $r->slug !== 'super_admin' && $r->permissions->pluck('slug')->every(fn ($p) => $me->hasPermission($p));
            })->pluck('name', 'id')->all();
    }

    protected function cfg(): array
    {
        return [
            'model' => User::class, 'route' => 'users', 'title' => 'Пользователи', 'singular' => 'Пользователь', 'add' => 'Добавить пользователя',
            'perm' => ['view' => 'users.manage', 'manage' => 'users.manage', 'delete' => 'users.manage'],
            'search' => ['name', 'email', 'phone'], 'with' => ['roles:id,name', 'branch:id,name'], 'order' => ['name', 'asc'],
            'columns' => [
                ['label' => 'Имя', 'key' => 'name', 'link' => true],
                ['label' => 'Email', 'key' => 'email'],
                ['label' => 'Телефон', 'key' => 'phone'],
                ['label' => 'Роли', 'value' => fn ($u) => $u->roles->pluck('name')->join(', ')],
                ['label' => 'Филиал', 'value' => fn ($u) => $u->branch?->name ?? __('Все')],
                ['label' => 'Активен', 'key' => 'is_active', 'type' => 'bool'],
                ['label' => 'Последний вход', 'value' => fn ($u) => $u->last_login_at?->format('d.m.Y H:i')],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Имя', 'rules' => 'required|string|max:120'],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'rules' => fn ($m) => ['nullable', 'email', 'max:150', 'required_without:phone', Rule::unique('users', 'email')->ignore($m?->id)]],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => fn ($m) => ['nullable', 'string', 'max:32', 'required_without:email', function ($a, $v, $fail) use ($m) {
                    $n = User::normalizePhone($v);
                    if ($n && User::withTrashed()->withoutGlobalScopes()->where('phone', $n)->when($m, fn ($q) => $q->where('id', '!=', $m->id))->exists()) {
                        $fail('Этот телефон уже используется.');
                    }
                }]],
                ['name' => 'password', 'label' => 'Пароль', 'type' => 'password', 'rules' => fn ($m) => [$m ? 'nullable' : 'required', Password::min(10)->letters()->numbers()], 'hint' => 'Минимум 10 символов, буквы и цифры. При редактировании оставьте пустым, чтобы не менять.'],
                ['name' => 'roles', 'label' => 'Роли', 'type' => 'multiselect', 'relation' => 'roles', 'options' => fn () => $this->assignableRoles(), 'rules' => 'required|array|min:1'],
                ['name' => 'branch_id', 'label' => 'Ограничить филиалом', 'type' => 'select', 'options' => fn () => Lookup::branches(), 'rules' => ['nullable', Lookup::exists('branches')], 'hint' => 'Пусто = доступ ко всем филиалам'],
                ['name' => 'locale', 'label' => 'Язык', 'type' => 'select', 'options' => ['ru' => 'Русский', 'uz' => 'O‘zbekcha'], 'rules' => 'nullable|in:ru,uz'],
                ['name' => 'is_active', 'label' => 'Активен', 'type' => 'checkbox', 'default' => true, 'rules' => 'boolean'],
            ],
        ];
    }

    protected function validated(Request $request, ?Model $model): array
    {
        $data = parent::validated($request, $model);
        $allowed = array_keys($this->assignableRoles());
        foreach ($data['roles'] ?? [] as $rid) {
            if (! in_array((int) $rid, $allowed, true)) {
                throw ValidationException::withMessages(['roles' => 'Нельзя назначить эту роль.']);
            }
        }
        if ($model && $model->id === auth()->id() && ! ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['is_active' => 'Нельзя отключить самого себя.']);
        }
        // a limited admin must not edit a user who outranks them
        if ($model && ! auth()->user()->isSuperAdmin() && $model->isSuperAdmin()) {
            abort(403);
        }

        return $data;
    }

    protected function cannotDelete(Model $model): ?string
    {
        return $model->id === auth()->id() ? __('Нельзя удалить самого себя.') : null;
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function index()
    {
        return view('roles.index', ['roles' => Role::available()->withCount('users', 'permissions')->orderBy('id')->get()]);
    }

    public function create()
    {
        return view('roles.form', ['role' => null, 'groups' => $this->groups(), 'selected' => []]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $role = Role::create(['organization_id' => Tenant::id(), 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'slug' => Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)), 'is_system' => false]);
        $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

        return redirect()->route('roles.index')->with('ok', __('Сохранено'));
    }

    public function edit(Role $role)
    {
        $this->guard($role);

        return view('roles.form', ['role' => $role, 'groups' => $this->groups(), 'selected' => $role->permissions()->pluck('slug')->all()]);
    }

    public function update(Request $request, Role $role)
    {
        $this->guard($role);
        $data = $this->validated($request);
        $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

        return redirect()->route('roles.index')->with('ok', __('Сохранено'));
    }

    public function destroy(Role $role)
    {
        $this->guard($role);
        if ($role->users()->exists()) {
            return back()->withErrors(['delete' => 'Роль назначена пользователям.']);
        }
        $role->delete();

        return redirect()->route('roles.index')->with('ok', __('Удалено'));
    }

    /** Only this organization's custom roles are editable; system roles are read-only. */
    protected function guard(Role $role): void
    {
        abort_unless($role->organization_id === Tenant::id() && ! $role->is_system, 403);
    }

    protected function groups(): array
    {
        $me = auth()->user();
        $out = [];
        foreach (Permission::ALL as $slug => [$group, $name]) {
            if ($me->hasPermission($slug)) {     // cannot grant what you do not have
                $out[$group][$slug] = $name;
            }
        }

        return $out;
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80', 'description' => 'nullable|string|max:255',
            'permissions' => 'nullable|array', 'permissions.*' => 'string|in:'.implode(',', array_keys(Permission::ALL)),
        ]);
    }

    protected function permissionIds(array $slugs): array
    {
        $allowed = collect($slugs)->filter(fn ($s) => auth()->user()->hasPermission($s));

        return Permission::whereIn('slug', $allowed)->pluck('id')->all();
    }
}

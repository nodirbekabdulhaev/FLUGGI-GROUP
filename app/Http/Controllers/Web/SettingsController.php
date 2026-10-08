<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\BackupService;
use App\Services\NotificationService;
use App\Support\Tenant;
use App\Support\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index()
    {
        $svc = app(NotificationService::class);

        return view('settings.index', [
            'org' => Tenant::organization(),
            'templates' => collect(NotificationService::DEFAULT_TEMPLATES)->map(fn ($d, $t) => Setting::get('tpl.'.$t, $d)),
            'defaults' => NotificationService::DEFAULT_TEMPLATES,
            'backups' => Gate::allows('backup.manage') ? app(BackupService::class)->list() : [],
            'system' => [
                'PHP' => PHP_VERSION, 'Laravel' => app()->version(), 'Env' => app()->environment(), 'Debug' => config('app.debug') ? 'ON ⚠' : 'off',
                'DB' => config('database.default'), 'Timezone' => config('app.timezone'),
                'Telegram bot' => config('services.telegram.bot_token') ? 'настроен' : 'не настроен',
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'currency' => 'required|string|max:8', 'timezone' => 'required|timezone', 'locale' => 'required|in:ru,uz',
            'logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
            'tpl' => 'nullable|array', 'tpl.*' => 'nullable|string|max:2000',
            'regenerate_key' => 'nullable|boolean',
        ]);
        $org = Organization::findOrFail(Tenant::id());
        $org->fill(\Illuminate\Support\Arr::only($data, ['name', 'currency', 'timezone', 'locale']));
        if ($request->hasFile('logo')) {
            Upload::delete($org->logo_path);
            $org->logo_path = Upload::store($request->file('logo'), 'logos', 'logo', ['jpg', 'png', 'webp']);
        }
        if ($request->boolean('regenerate_key')) {
            $org->webhook_key = Str::random(40);
            AuditLog::record('webhook_key_regenerated', $org);
        }
        $org->save();

        foreach (array_keys(NotificationService::DEFAULT_TEMPLATES) as $t) {
            if (array_key_exists($t, $data['tpl'] ?? [])) {
                Setting::put('tpl.'.$t, $data['tpl'][$t] ?: null);
            }
        }

        return back()->with('ok', __('Сохранено'));
    }

    public function dictionaries()
    {
        return view('settings.dictionaries');
    }

    public function backup()
    {
        $name = app(BackupService::class)->run();
        AuditLog::record('backup_created', null, [], ['file' => $name], 'Backup');

        return back()->with('ok', __('Резервная копия создана').": $name");
    }

    public function download(string $name)
    {
        $svc = app(BackupService::class);
        AuditLog::record('backup_downloaded', null, [], ['file' => $name], 'Backup');

        return response()->download($svc->path($name), $name);
    }

    public function restore(Request $request, string $name)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $request->validate(['confirm' => 'required|in:RESTORE']);
        $svc = app(BackupService::class);
        $svc->path($name);                   // validates the file name before touching anything
        $svc->backupBeforeRestore();
        $n = $svc->restore($name);

        return redirect()->route('login')->with('ok', "База восстановлена из $name ($n операций). Войдите заново.");
    }
}

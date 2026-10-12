<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/** Настройки: разделы всех модулей, отфильтрованные по правам (и только существующие страницы). */
class SettingsController extends Controller
{
    /** [адрес, ключ подписи, право, мин. область] */
    public const TABS = [
        ['/settings/teams', 'tabTeams', 'employee.manage', 'OWN'],
        ['/settings/references', 'tabReferences', 'reference.manage', 'OWN'],
        ['/settings/tariffs', 'tabTariffs', 'reference.manage', 'OWN'],
        ['/settings/finance', 'tabFinance', 'reference.manage', 'OWN'],
        ['/settings/templates', 'tabTemplates', 'reference.manage', 'OWN'],
        ['/settings/commission-rules', 'tabCommissionRules', 'commission_rule.manage', 'OWN'],
        ['/settings/schedules', 'tabSchedules', 'schedule.manage', 'OWN'],
        ['/settings/documents', 'tabDocuments', 'settings.manage', 'OWN'],
        ['/settings/integrations', 'tabIntegrations', 'settings.manage', 'OWN'],
        ['/settings/automation', 'tabAutomation', 'settings.manage', 'OWN'],
        ['/settings/roles', 'tabRoles', 'role.manage', 'ALL'],
        ['/settings/audit', 'tabAudit', 'audit.read', 'ALL'],
    ];

    /** Вкладки, доступные пользователю (страница должна существовать). */
    public static function tabs(): array
    {
        $routes = Route::getRoutes();
        $out = [];
        foreach (self::TABS as [$href, $key, $perm, $scope]) {
            if (! access()->can($perm, $scope)) {
                continue;
            }
            try {
                $routes->match(Request::create($href, 'GET'));
            } catch (\Throwable) {
                continue;
            }
            $out[] = ['href' => $href, 'key' => $key];
        }

        return $out;
    }

    public function index(): View
    {
        return view('settings.index', ['tabs' => self::tabs()]);
    }
}

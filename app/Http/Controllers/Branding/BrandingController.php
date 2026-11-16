<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Services\Branding\LogoProcessor;
use App\Support\Audit;
use App\Support\Brand;
use App\Support\Lang;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Бренд: название проекта, логотип (иконки PWA, favicon) и цвет темы. Доступ — settings.manage. */
class BrandingController extends Controller
{
    public function index(): View
    {
        return view('settings.branding', ['brand' => brand(), 'gd' => LogoProcessor::available()]);
    }

    public function save(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'shortName' => ['required', 'string', 'max:20'],
            'themeColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // SVG не принимаем: в нём может быть скрипт
            'logo' => ['nullable', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp'],
        ], [
            'themeColor.regex' => t('branding.errors.color'),
            'logo.mimes' => t('branding.errors.type'),
            'logo.mimetypes' => t('branding.errors.type'),
            'logo.max' => t('branding.errors.size'),
        ]);
        $version = null;
        if ($request->hasFile('logo')) {
            // Ошибка (нет GD, мелкое или битое изображение) — прежний логотип остаётся
            $version = LogoProcessor::process($request->file('logo')->getRealPath());
        }
        $before = Settings::get('branding');
        $value = [
            'name' => trim(preg_replace('/\s+/u', ' ', $data['name'])),
            'shortName' => trim(preg_replace('/\s+/u', ' ', $data['shortName'])),
            'themeColor' => filled($data['themeColor'] ?? null) ? strtolower($data['themeColor']) : null,
            'version' => $version ?? brand()->version(),
        ];
        try {
            $after = DB::transaction(function () use ($before, $value) {
                $after = Settings::save('branding', $value);
                Audit::log('settings.branding', 'setting', null, ['branding' => ['old' => $before, 'new' => $after]]);

                return $after;
            });
        } catch (Throwable $e) {
            $version && File::deleteDirectory(Brand::dir($version));
            throw $e;
        }
        LogoProcessor::prune($after['version']);

        return $request->expectsJson() ? response()->json($after) : back()->with('ok', t('branding.saved'));
    }

    /** Стандартные название, логотип и цвет. */
    public function reset(Request $request): JsonResponse|RedirectResponse
    {
        $after = DB::transaction(function () {
            $before = Settings::get('branding');
            $after = Settings::save('branding', Settings::DEFAULTS['branding']);
            Audit::log('settings.branding_reset', 'setting', null, ['branding' => ['old' => $before, 'new' => $after]]);

            return $after;
        });
        LogoProcessor::prune(null);

        return $request->expectsJson() ? response()->json($after) : back()->with('ok', t('branding.resetDone'));
    }

    /** Иконка набора (публично: браузер и PWA запрашивают иконки без сессии). */
    public function file(Request $request, string $file): BinaryFileResponse
    {
        $brand = brand();
        $path = $brand->path($file);
        abort_if($path === null, 404);
        // Адрес с текущей версией не меняется никогда — кэш на год; без версии — ненадолго
        $versioned = $request->query('v') !== null && $request->query('v') === ($brand->version() ?? Brand::DEFAULT_VERSION);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ]);
    }

    /** Манифест PWA: название, цвет и иконки из настроек бренда. */
    public function manifest(): JsonResponse
    {
        $b = brand();
        $tagline = Lang::get('app.tagline', [], 'uz').' / '.Lang::get('app.tagline', [], 'ru');

        return response()->json([
            'name' => $b->name(),
            'short_name' => $b->shortName(),
            'description' => $tagline,
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#fafafa',
            'theme_color' => $b->themeColor(),
            'icons' => [
                ['src' => $b->icon('icon-192'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $b->icon('icon-512'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $b->icon('maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

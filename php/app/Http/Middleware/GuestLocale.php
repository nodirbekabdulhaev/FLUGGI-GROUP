<?php

namespace App\Http\Middleware;

use App\Support\Lang;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Язык до входа: ?lang=uz|ru → cookie, иначе язык браузера. */
class GuestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->query('lang');
        if (in_array($lang, Lang::LOCALES, true)) {
            $request->session()->put('locale', $lang);
        }
        $locale = $request->session()->get('locale')
            ?? ($request->getPreferredLanguage(['ru', 'uz']) ?: 'ru');
        app()->setLocale(in_array($locale, Lang::LOCALES, true) ? $locale : 'ru');

        return $next($request);
    }
}

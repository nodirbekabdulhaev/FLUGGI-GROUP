<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Глобальный поиск (ТЗ §44): ищет только среди доступных пользователю записей. */
class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return response()->json(['message' => t('search.minLength'), 'errors' => ['q' => [t('search.minLength')]]], 422);
        }

        return response()->json(SearchService::search(access(), $q));
    }
}

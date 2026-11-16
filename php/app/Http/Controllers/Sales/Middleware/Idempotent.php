<?php

namespace App\Http\Controllers\Sales\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита от дублей при двойном клике и повторах сети (ТЗ §71), как в прежней версии.
 * Ключ (поле формы _idem или заголовок Idempotency-Key) резервируется ДО выполнения запроса
 * (уникальный PK): второй такой же запрос получает сохранённый результат или «уже выполняется».
 * При ошибке ключ освобождается — запрос можно повторить.
 */
class Idempotent
{
    /** status_code = 0 — запрос с этим ключом ещё выполняется. */
    private const PENDING = 0;

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->header('Idempotency-Key') ?? $request->input('_idem');
        $access = access(false);
        if (! $request->isMethod('POST') || ! is_string($raw) || ! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $raw) || ! $access) {
            return $next($request);
        }
        $key = $access->id().':'.$raw;
        $route = 'POST /'.ltrim($request->path(), '/');

        try {
            IdempotencyKey::create(['key' => $key, 'user_id' => $access->id(), 'route' => $route, 'status_code' => self::PENDING, 'response' => []]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            $saved = IdempotencyKey::find($key);

            return $this->replay($request, $saved, $route);
        }

        $response = $next($request);
        $failed = $response->getStatusCode() >= 400
            || ($response instanceof RedirectResponse && $request->hasSession() && ($request->session()->has('errors') || $request->session()->has('error')));
        if ($failed) {
            IdempotencyKey::where('key', $key)->delete();

            return $response;
        }
        IdempotencyKey::where('key', $key)->update([
            'status_code' => $response->getStatusCode(),
            'response' => $response instanceof JsonResponse
                ? ['json' => $response->getData(true)]
                : ['redirect' => $response instanceof RedirectResponse ? $response->getTargetUrl() : null, 'ok' => $request->hasSession() ? $request->session()->get('ok') : null],
        ]);

        return $response;
    }

    private function replay(Request $request, ?IdempotencyKey $saved, string $route): Response
    {
        $message = match (true) {
            ! $saved || $saved->route !== $route => t('sales.errors.idempotencyUsed'),
            $saved->status_code === self::PENDING => t('sales.errors.idempotencyPending'),
            default => null,
        };
        if ($message !== null) {
            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => []], 409)
                : back()->with('error', $message);
        }
        $data = $saved->response ?? [];
        if (array_key_exists('json', $data)) {
            return response()->json($data['json'], $saved->status_code)->header('Idempotent-Replay', 'true');
        }
        $redirect = redirect()->to($data['redirect'] ?? url()->previous())->header('Idempotent-Replay', 'true');

        return ! empty($data['ok']) ? $redirect->with('ok', $data['ok']) : $redirect;
    }
}

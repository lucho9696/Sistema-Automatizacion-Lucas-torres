<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotencia para operaciones que crean dinero o stock (ventas, cobros, gastos, compras).
 * El cliente envía `Idempotency-Key`; un reintento con la misma clave devuelve la respuesta
 * original sin volver a ejecutar la operación. Misma clave con otro cuerpo => 422.
 * El frontend genera una clave por envío de formulario.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $next($request);
        }
        if (strlen($key) > 120 || ! preg_match('/^[A-Za-z0-9_\-:.]+$/', $key)) {
            return new JsonResponse(['message' => 'Idempotency-Key inválida.'], 400);
        }

        $userId = $request->user()?->id;
        $scope = $request->method().' '.$request->route()?->uri();
        $hash = hash('sha256', $request->getPathInfo().'|'.json_encode($request->all()));

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'user_id' => $userId,
            'scope' => $scope,
            'key' => $key,
            'request_hash' => $hash,
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            $existing = IdempotencyKey::query()
                ->where('user_id', $userId)->where('scope', $scope)->where('key', $key)->first();
            if ($existing && $existing->request_hash !== $hash) {
                return new JsonResponse(['message' => 'La Idempotency-Key ya se usó con otros datos.'], 422);
            }
            if (! $existing || $existing->status !== 'completed') {
                return new JsonResponse(['message' => 'La operación con esta Idempotency-Key está en proceso.'], 409);
            }

            return (new JsonResponse($existing->response_body, $existing->response_status))
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        $record = IdempotencyKey::query()->where('user_id', $userId)->where('scope', $scope)->where('key', $key);
        if ($response->getStatusCode() >= 500) {
            $record->delete(); // permitir reintento
        } else {
            $record->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => json_validate((string) $response->getContent()) ? $response->getContent() : null,
                'updated_at' => now(),
            ]);
        }

        return $response;
    }
}

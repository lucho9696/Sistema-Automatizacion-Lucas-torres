<?php

namespace App\Domain\Integrations;

use App\Models\SyncRun;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Registra cada ejecución de una integración: estado, duración, conteos y error sanitizado. */
class SyncRunRecorder
{
    /**
     * @template T
     *
     * @param  callable(SyncRun): T  $work
     * @return T
     */
    public function run(string $provider, string $mode, array $parameters, callable $work)
    {
        $run = SyncRun::create([
            'provider' => $provider, 'mode' => $mode, 'status' => 'running',
            'parameters' => $parameters, 'user_id' => Auth::id(), 'started_at' => now(),
        ]);
        $start = hrtime(true);
        try {
            $result = $work($run);
            $run->status = 'success';

            return $result;
        } catch (Throwable $e) {
            $run->status = 'failed';
            $run->error = $e instanceof IntegrationException ? $e->getMessage() : 'Error inesperado (ver logs del servidor).';
            Log::warning('Integración fallida', ['provider' => $provider, 'sync_run_id' => $run->id, 'exception' => get_class($e)]);
            throw $e;
        } finally {
            $run->finished_at = now();
            $run->duration_ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $run->save();
        }
    }
}

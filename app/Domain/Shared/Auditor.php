<?php

namespace App\Domain\Shared;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Registro de auditoría de acciones financieras y de inventario.
 * Nunca guardar secretos, tokens ni datos de pago sensibles en old/new values.
 */
class Auditor
{
    private const REDACTED = ['password', 'remember_token', 'token', 'access_token', 'secret'];

    public function record(string $event, ?Model $model = null, ?array $old = null, ?array $new = null, ?string $reason = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model ? $model->getMorphClass() : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old ? $this->redact($old) : null,
            'new_values' => $new ? $this->redact($new) : null,
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'request_id' => Request::header('X-Request-Id'),
        ]);
    }

    private function redact(array $values): array
    {
        foreach ($values as $k => $v) {
            if (in_array(strtolower((string) $k), self::REDACTED, true)) {
                $values[$k] = '[redactado]';
            } elseif (is_array($v)) {
                $values[$k] = $this->redact($v);
            }
        }

        return $values;
    }
}

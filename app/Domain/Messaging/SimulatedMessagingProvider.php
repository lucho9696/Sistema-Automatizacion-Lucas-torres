<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Str;

/** Modo por defecto: registra la respuesta pero no envía nada fuera del sistema. */
class SimulatedMessagingProvider implements MessagingProvider
{
    public function mode(): string
    {
        return 'simulado';
    }

    public function sendText(string $to, string $body): SendResult
    {
        return new SendResult('sim.'.Str::uuid(), 'simulado');
    }

    public function sendTemplate(string $to, string $name, string $language, array $params = []): SendResult
    {
        return new SendResult('sim.'.Str::uuid(), 'simulado');
    }
}

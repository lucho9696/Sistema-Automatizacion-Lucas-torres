<?php

namespace App\Domain\Messaging;

final class SendResult
{
    /** @param string $status simulado | enviado */
    public function __construct(public readonly string $externalId, public readonly string $status) {}
}

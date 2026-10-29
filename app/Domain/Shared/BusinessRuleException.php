<?php

namespace App\Domain\Shared;

use RuntimeException;

/** Violación de una regla de negocio. Se responde como HTTP 422 con un mensaje accionable. */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}

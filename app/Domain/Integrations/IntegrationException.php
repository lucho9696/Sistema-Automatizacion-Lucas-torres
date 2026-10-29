<?php

namespace App\Domain\Integrations;

use RuntimeException;

/** Error de una integración externa con un mensaje seguro para mostrar (sin secretos). */
class IntegrationException extends RuntimeException {}

<?php

namespace App\Enums;

/**
 * Roles iniciales (especificación §4). Los permisos se resuelven en
 * App\Providers\AppServiceProvider mediante Gates, para poder agregar
 * usuarios y roles sin rediseñar el modelo.
 */
enum Role: string
{
    case Admin = 'admin';
    case Vendedor = 'vendedor';
    case Analista = 'analista';
}

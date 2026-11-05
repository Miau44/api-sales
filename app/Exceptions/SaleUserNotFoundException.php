<?php

namespace App\Exceptions;

use RuntimeException;

class SaleUserNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No existe el usuario requerido para registrar ventas (ID 1).');
    }
}

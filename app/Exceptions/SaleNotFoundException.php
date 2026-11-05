<?php

namespace App\Exceptions;

use RuntimeException;

class SaleNotFoundException extends RuntimeException
{
    public function __construct(int $saleId)
    {
        parent::__construct("No se encontró la venta con ID {$saleId}.");
    }
}

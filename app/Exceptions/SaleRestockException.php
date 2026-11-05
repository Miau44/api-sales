<?php

namespace App\Exceptions;

use RuntimeException;

class SaleRestockException extends RuntimeException
{
    public function __construct(?int $productId = null)
    {
        $message = $productId === null
            ? 'No fue posible completar la eliminación y restitución de inventario.'
            : "No fue posible restituir el inventario del producto con ID {$productId}.";

        parent::__construct($message);
    }
}

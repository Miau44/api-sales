<?php

namespace App\Services;

use App\Exceptions\SaleUserNotFoundException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    private const SALES_USER_ID = 1;

    private const MAX_QUANTITY = 4_294_967_295;

    /**
     * @param array<int, array{product_id: int|string, quantity: int|string}> $items
     */
    public function create(array $items): Sale
    {
        $quantitiesByProduct = $this->consolidateItems($items);

        return DB::transaction(function () use ($quantitiesByProduct): Sale {
            if (! User::query()->whereKey(self::SALES_USER_ID)->exists()) {
                throw new SaleUserNotFoundException();
            }

            $products = [];

            // Acquire row locks one at a time in a stable order to reduce deadlocks.
            foreach (array_keys($quantitiesByProduct) as $productId) {
                $product = Product::query()
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ["El producto con ID {$productId} ya no está disponible."],
                    ]);
                }

                $quantity = $quantitiesByProduct[$productId];
                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Stock insuficiente para el producto {$product->name}. " .
                            "Disponible: {$product->stock}; solicitado: {$quantity}.",
                        ],
                    ]);
                }

                $priceInMinorUnits = Money::toMinorUnits((string) $product->price);
                if ($priceInMinorUnits > intdiv(Money::MAX_MINOR_UNITS, $quantity)) {
                    throw ValidationException::withMessages([
                        'items' => ['El importe de la venta supera el máximo permitido.'],
                    ]);
                }

                $subtotalInMinorUnits = $priceInMinorUnits * $quantity;
                $products[$productId] = [
                    'model' => $product,
                    'quantity' => $quantity,
                    'price' => Money::toDecimalString($priceInMinorUnits),
                    'subtotal' => $subtotalInMinorUnits,
                ];
            }

            $totalInMinorUnits = 0;
            foreach ($products as $line) {
                if ($totalInMinorUnits > Money::MAX_MINOR_UNITS - $line['subtotal']) {
                    throw ValidationException::withMessages([
                        'items' => ['El total de la venta supera el máximo permitido.'],
                    ]);
                }

                $totalInMinorUnits += $line['subtotal'];
            }

            $sale = Sale::query()->create([
                'user_id' => self::SALES_USER_ID,
                'total' => Money::toDecimalString($totalInMinorUnits),
            ]);

            foreach ($products as $productId => $line) {
                $sale->details()->create([
                    'product_id' => $productId,
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'subtotal' => Money::toDecimalString($line['subtotal']),
                ]);

                $updatedRows = Product::query()
                    ->whereKey($productId)
                    ->where('stock', '>=', $line['quantity'])
                    ->decrement('stock', $line['quantity']);

                if ($updatedRows !== 1) {
                    throw ValidationException::withMessages([
                        'items' => ["No fue posible actualizar el stock del producto con ID {$productId}."],
                    ]);
                }
            }

            return $sale;
        }, attempts: 3);
    }

    /**
     * @param array<int, array{product_id: int|string, quantity: int|string}> $items
     * @return array<int, int>
     */
    private function consolidateItems(array $items): array
    {
        $quantitiesByProduct = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];
            $currentQuantity = $quantitiesByProduct[$productId] ?? 0;

            if ($quantity > self::MAX_QUANTITY - $currentQuantity) {
                throw ValidationException::withMessages([
                    'items' => ['La cantidad consolidada de un producto supera el máximo permitido.'],
                ]);
            }

            $quantitiesByProduct[$productId] = $currentQuantity + $quantity;
        }

        ksort($quantitiesByProduct, SORT_NUMERIC);

        return $quantitiesByProduct;
    }
}

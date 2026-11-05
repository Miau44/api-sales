<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SaleUserNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function store(StoreSaleRequest $request, SaleService $saleService): JsonResponse
    {
        try {
            $sale = $saleService->create($request->validated('items'));
        } catch (SaleUserNotFoundException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'message' => 'Venta creada correctamente',
            'sale_id' => $sale->id,
            'total' => Money::toApiNumber(Money::toMinorUnits((string) $sale->total)),
        ], 201);
    }
}

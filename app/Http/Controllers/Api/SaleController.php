<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SaleUserNotFoundException;
use App\Exceptions\SaleNotFoundException;
use App\Exceptions\SaleRestockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function index(SaleService $saleService): JsonResponse
    {
        $sales = $saleService->list()->map(fn ($sale): array => [
            'id' => $sale->id,
            'total' => Money::toApiNumber(Money::toMinorUnits((string) $sale->total)),
            'created_at' => $sale->created_at?->toDateString(),
            'details' => $sale->details->map(fn ($detail): array => [
                'product' => $detail->product?->name,
                'quantity' => $detail->quantity,
                'price' => Money::toApiNumber(Money::toMinorUnits((string) $detail->price)),
                'subtotal' => Money::toApiNumber(Money::toMinorUnits((string) $detail->subtotal)),
            ])->values(),
        ])->values();

        return response()->json($sales);
    }

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

    public function destroy(int $id, SaleService $saleService): JsonResponse
    {
        try {
            $saleService->delete($id);
        } catch (SaleNotFoundException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        } catch (SaleRestockException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'message' => 'Venta eliminada correctamente',
        ]);
    }
}

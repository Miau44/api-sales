<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;

Route::get('/test', function () {
  return response()->json([
    'app' => config('app.name'),
    'env' => app()->environment(),
    'version' => app()->version(),
    'status' => 'ok',
  ]);
});

Route::get('/products', [ProductController::class, 'index']);
Route::post('/sales', [SaleController::class, 'store']);

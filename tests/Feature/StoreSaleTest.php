<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_it_creates_a_sale_with_server_side_price_and_stock_update(): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('12.34', 10);

        $response = $this->postJson('/api/sales', [
            'user_id' => 1,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Venta creada correctamente')
            ->assertJsonPath('total', 24.68)
            ->assertJsonStructure(['message', 'sale_id', 'total']);

        $this->assertDatabaseHas('sales', ['user_id' => 1, 'total' => '24.68']);
        $this->assertDatabaseHas('sale_details', [
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => '12.34',
            'subtotal' => '24.68',
        ]);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_it_creates_a_sale_with_multiple_products(): void
    {
        $this->createSalesUser();
        $first = $this->createProduct('10.01', 10);
        $second = $this->createProduct('2.50', 10);

        $response = $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $first->id, 'quantity' => 2],
                ['product_id' => $second->id, 'quantity' => 3],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('total', 27.52);
        $this->assertDatabaseCount('sale_details', 2);
        $this->assertSame(8, $first->fresh()->stock);
        $this->assertSame(7, $second->fresh()->stock);
    }

    public function test_it_rejects_a_product_that_does_not_exist(): void
    {
        $this->createSalesUser();

        $this->postJson('/api/sales', [
            'items' => [['product_id' => 999999, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');

        $this->assertDatabaseCount('sales', 0);
    }

    #[DataProvider('invalidQuantities')]
    public function test_it_rejects_zero_negative_and_non_integer_quantities(mixed $quantity): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('5.00', 10);

        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public static function invalidQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fractional' => [1.25],
        ];
    }

    public function test_it_rejects_insufficient_stock_without_partial_writes(): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('5.00', 2);

        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_details', 0);
        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_it_consolidates_repeated_product_ids_into_one_detail(): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('12.34', 10);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ])->assertCreated()->assertJsonPath('total', 61.70);

        $this->assertDatabaseCount('sale_details', 1);
        $this->assertDatabaseHas('sale_details', [
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => '12.34',
            'subtotal' => '61.70',
        ]);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_it_ignores_client_supplied_user_price_subtotal_and_total(): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('12.34', 10);

        $response = $this->postJson('/api/sales', [
            'user_id' => 1,
            'total' => 0.01,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 0.01,
                'subtotal' => 0.02,
            ]],
        ]);

        $response->assertCreated()->assertJsonPath('total', 24.68);
        $this->assertDatabaseHas('sales', ['user_id' => 1, 'total' => '24.68']);
        $this->assertDatabaseHas('sale_details', [
            'price' => '12.34',
            'subtotal' => '24.68',
        ]);
    }

    public function test_it_persists_the_historical_unit_price_on_the_sale_detail(): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('12.34', 10);

        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $product->update(['price' => '99.99']);

        $this->assertDatabaseHas('sale_details', [
            'product_id' => $product->id,
            'price' => '12.34',
            'subtotal' => '24.68',
        ]);
    }

    public function test_it_rolls_back_sale_and_stock_when_detail_persistence_fails(): void
    {
        $this->createSalesUser();
        $product = $this->createProduct('5.00', 10);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_fail_sale_detail_insert
            BEFORE INSERT ON sale_details
            BEGIN
                SELECT RAISE(ABORT, 'forced detail write failure');
            END;
            SQL);

        try {
            $this->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])->assertInternalServerError();
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS test_fail_sale_detail_insert');
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_details', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_it_rejects_the_sale_if_static_user_id_one_does_not_exist(): void
    {
        $product = $this->createProduct('5.00', 10);

        $this->postJson('/api/sales', [
            'user_id' => 1,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertConflict()->assertJsonPath('message', 'No existe el usuario requerido para registrar ventas (ID 1).');

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    private function createSalesUser(): User
    {
        return User::factory()->create([
            'name' => 'Sales Test User',
            'email' => 'sales-test@example.test',
        ]);
    }

    private function createProduct(string $price, int $stock): Product
    {
        return Product::query()->create([
            'name' => 'Test Product '.uniqid(),
            'price' => $price,
            'stock' => $stock,
        ]);
    }
}

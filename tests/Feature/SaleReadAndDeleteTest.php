<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleReadAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_sales_list_is_an_empty_json_array_when_there_are_no_sales(): void
    {
        $this->getJson('/api/sales')->assertOk()->assertExactJson([]);
    }

    public function test_sales_list_returns_a_sale_with_historical_details(): void
    {
        $user = $this->createSalesUser();
        $product = $this->createProduct('12.34', 8);
        $sale = $this->createSale($user, '24.68', '2026-10-20 09:30:00');
        $this->createDetail($sale, $product, 2, '12.34', '24.68');
        $product->update(['price' => '99.99']);

        $this->getJson('/api/sales')->assertOk()->assertExactJson([[
            'id' => $sale->id,
            'total' => 24.68,
            'created_at' => '2026-10-20',
            'details' => [[
                'product' => $product->name,
                'quantity' => 2,
                'price' => 12.34,
                'subtotal' => 24.68,
            ]],
        ]]);
    }

    public function test_sales_list_orders_newest_first_with_id_as_a_stable_tiebreaker(): void
    {
        $user = $this->createSalesUser();
        $older = $this->createSale($user, '10.00', '2026-10-20 09:30:00');
        $newer = $this->createSale($user, '20.00', '2026-10-21 09:30:00');
        $sameTimeHigherId = $this->createSale($user, '30.00', '2026-10-21 09:30:00');

        $response = $this->getJson('/api/sales')->assertOk();

        $response->assertJsonPath('0.id', $sameTimeHigherId->id)
            ->assertJsonPath('1.id', $newer->id)
            ->assertJsonPath('2.id', $older->id);
    }

    public function test_sales_list_excludes_soft_deleted_sales(): void
    {
        $user = $this->createSalesUser();
        $visible = $this->createSale($user, '10.00', '2026-10-20 09:30:00');
        $deleted = $this->createSale($user, '20.00', '2026-10-21 09:30:00');
        $deleted->delete();

        $this->getJson('/api/sales')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $visible->id);
    }

    public function test_delete_soft_deletes_sale_restores_stock_and_keeps_detail_history(): void
    {
        $user = $this->createSalesUser();
        $product = $this->createProduct('12.34', 8);
        $sale = $this->createSale($user, '24.68', '2026-10-20 09:30:00');
        $detail = $this->createDetail($sale, $product, 2, '12.34', '24.68');

        $this->deleteJson("/api/sales/{$sale->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Venta eliminada correctamente']);

        $this->assertNotNull(DB::table('sales')->where('id', $sale->id)->value('deleted_at'));
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('sale_details', [
            'id' => $detail->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => '12.34',
            'subtotal' => '24.68',
        ]);
    }

    public function test_delete_returns_not_found_for_a_missing_sale(): void
    {
        $this->deleteJson('/api/sales/987654')->assertNotFound();
    }

    public function test_delete_returns_not_found_for_a_sale_that_was_already_deleted(): void
    {
        $user = $this->createSalesUser();
        $product = $this->createProduct('5.00', 10);
        $sale = $this->createSale($user, '15.00', '2026-10-20 09:30:00');
        $this->createDetail($sale, $product, 3, '5.00', '15.00');
        $sale->delete();

        $this->deleteJson("/api/sales/{$sale->id}")->assertNotFound();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, DB::table('sale_details')->where('sale_id', $sale->id)->count());
    }

    public function test_repeated_delete_does_not_restore_stock_twice(): void
    {
        $user = $this->createSalesUser();
        $product = $this->createProduct('5.00', 7);
        $sale = $this->createSale($user, '15.00', '2026-10-20 09:30:00');
        $this->createDetail($sale, $product, 3, '5.00', '15.00');

        $this->deleteJson("/api/sales/{$sale->id}")->assertOk();
        $this->deleteJson("/api/sales/{$sale->id}")->assertNotFound();

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_delete_aggregates_repeated_product_details_before_restoring_stock(): void
    {
        $user = $this->createSalesUser();
        $product = $this->createProduct('5.00', 10);
        $sale = $this->createSale($user, '25.00', '2026-10-20 09:30:00');
        $this->createDetail($sale, $product, 2, '5.00', '10.00');
        $this->createDetail($sale, $product, 3, '5.00', '15.00');

        $this->deleteJson("/api/sales/{$sale->id}")->assertOk();

        $this->assertSame(15, $product->fresh()->stock);
        $this->assertSame(2, DB::table('sale_details')->where('sale_id', $sale->id)->count());
    }

    public function test_delete_rolls_back_prior_restock_when_a_later_stock_update_fails(): void
    {
        $user = $this->createSalesUser();
        $firstProduct = $this->createProduct('2.00', 8);
        $secondProduct = $this->createProduct('3.00', 7);
        $sale = $this->createSale($user, '13.00', '2026-10-20 09:30:00');
        $this->createDetail($sale, $firstProduct, 2, '2.00', '4.00');
        $this->createDetail($sale, $secondProduct, 3, '3.00', '9.00');

        DB::unprepared("CREATE TRIGGER test_fail_second_stock_restore BEFORE UPDATE OF stock ON products WHEN NEW.id = {$secondProduct->id} BEGIN SELECT RAISE(ABORT, 'forced stock restore failure'); END;");

        try {
            $this->deleteJson("/api/sales/{$sale->id}")->assertInternalServerError();
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS test_fail_second_stock_restore');
        }

        $this->assertNull(DB::table('sales')->where('id', $sale->id)->value('deleted_at'));
        $this->assertSame(8, $firstProduct->fresh()->stock);
        $this->assertSame(7, $secondProduct->fresh()->stock);
        $this->assertSame(2, DB::table('sale_details')->where('sale_id', $sale->id)->count());
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

    private function createSale(User $user, string $total, string $createdAt): Sale
    {
        $sale = Sale::query()->create([
            'user_id' => $user->id,
            'total' => $total,
        ]);
        $sale->forceFill(['created_at' => Carbon::parse($createdAt)])->save();

        return $sale;
    }

    private function createDetail(
        Sale $sale,
        Product $product,
        int $quantity,
        string $price,
        string $subtotal,
    ): SaleDetail {
        return $sale->details()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal,
        ]);
    }
}

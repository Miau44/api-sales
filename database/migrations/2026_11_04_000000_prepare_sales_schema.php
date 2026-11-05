<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertSupportedDriver();

        $orphans = [
            'sales without a user' => DB::table('sales')
                ->leftJoin('users', 'sales.user_id', '=', 'users.id')
                ->whereNull('users.id')
                ->count(),
            'sale details without a sale' => DB::table('sale_details')
                ->leftJoin('sales', 'sale_details.sale_id', '=', 'sales.id')
                ->whereNull('sales.id')
                ->count(),
            'sale details without a product' => DB::table('sale_details')
                ->leftJoin('products', 'sale_details.product_id', '=', 'products.id')
                ->whereNull('products.id')
                ->count(),
        ];

        $invalidStock = DB::table('products')->where('stock', '<', 0)->count();
        $invalidQuantities = DB::table('sale_details')->where('quantity', '<=', 0)->count();

        $problems = array_keys(array_filter($orphans));
        if ($invalidStock > 0) {
            $problems[] = 'products with negative stock';
        }
        if ($invalidQuantities > 0) {
            $problems[] = 'sale details with non-positive quantity';
        }

        if ($problems !== []) {
            throw new \RuntimeException(
                'Cannot prepare sales schema until existing data is corrected: '.implode(', ', $problems).'.'
            );
        }

        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->change();
        });

        Schema::table('sale_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_id')->change();
            $table->unsignedBigInteger('product_id')->change();
            $table->unsignedInteger('quantity')->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('stock')->change();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('sale_details', function (Blueprint $table): void {
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });

        $this->addNonNegativeStockConstraint();
        $this->addPositiveQuantityConstraint();
    }

    public function down(): void
    {
        $this->assertSupportedDriver();

        $valuesOutsideOriginalRanges = [
            'sales.user_id' => DB::table('sales')->where('user_id', '>', 4294967295)->exists(),
            'sale_details.sale_id' => DB::table('sale_details')->where('sale_id', '>', 4294967295)->exists(),
            'sale_details.product_id' => DB::table('sale_details')->where('product_id', '>', 4294967295)->exists(),
            'sale_details.quantity' => DB::table('sale_details')->where('quantity', '>', 2147483647)->exists(),
            'products.stock' => DB::table('products')->where('stock', '>', 2147483647)->exists(),
        ];

        if (in_array(true, $valuesOutsideOriginalRanges, true)) {
            throw new \RuntimeException(
                'Cannot roll back sales schema because values exceed the original column ranges.'
            );
        }

        $this->dropPositiveQuantityConstraint();
        $this->dropNonNegativeStockConstraint();

        Schema::table('sale_details', function (Blueprint $table): void {
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['product_id']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('sale_details', function (Blueprint $table): void {
            $table->integer('quantity')->change();
            $table->unsignedInteger('sale_id')->change();
            $table->unsignedInteger('product_id')->change();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->integer('stock')->change();
        });
    }

    private function assertSupportedDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException(
                'The sales schema migration supports MySQL and SQLite only; no schema changes were made.'
            );
        }
    }

    private function addNonNegativeStockConstraint(): void
    {
        if (DB::getDriverName() === 'mysql') {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER products_stock_non_negative_insert
                BEFORE INSERT ON products
                FOR EACH ROW WHEN NEW.stock < 0
                BEGIN
                    SELECT RAISE(ABORT, 'products.stock must be non-negative');
                END;
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER products_stock_non_negative_update
                BEFORE UPDATE OF stock ON products
                FOR EACH ROW WHEN NEW.stock < 0
                BEGIN
                    SELECT RAISE(ABORT, 'products.stock must be non-negative');
                END;
                SQL);
            return;
        }

        throw new RuntimeException('The sales schema migration supports MySQL and SQLite only.');
    }

    private function dropNonNegativeStockConstraint(): void
    {
        if (DB::getDriverName() === 'mysql') {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS products_stock_non_negative_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS products_stock_non_negative_update');
        }
    }

    private function addPositiveQuantityConstraint(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER sale_details_quantity_positive_insert
                BEFORE INSERT ON sale_details
                FOR EACH ROW
                BEGIN
                    IF NEW.quantity <= 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sale_details.quantity must be greater than zero';
                    END IF;
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER sale_details_quantity_positive_update
                BEFORE UPDATE ON sale_details
                FOR EACH ROW
                BEGIN
                    IF NEW.quantity <= 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sale_details.quantity must be greater than zero';
                    END IF;
                END
                SQL);
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sale_details_quantity_positive_insert
            BEFORE INSERT ON sale_details
            FOR EACH ROW WHEN NEW.quantity <= 0
            BEGIN
                SELECT RAISE(ABORT, 'sale_details.quantity must be greater than zero');
            END;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sale_details_quantity_positive_update
            BEFORE UPDATE OF quantity ON sale_details
            FOR EACH ROW WHEN NEW.quantity <= 0
            BEGIN
                SELECT RAISE(ABORT, 'sale_details.quantity must be greater than zero');
            END;
            SQL);
    }

    private function dropPositiveQuantityConstraint(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS sale_details_quantity_positive_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS sale_details_quantity_positive_update');
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS sale_details_quantity_positive_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS sale_details_quantity_positive_update');
    }
};

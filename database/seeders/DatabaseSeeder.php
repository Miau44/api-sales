<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Database\QueryException;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->ensureTestUser();

        if (! Product::query()->exists()) {
            $this->call(ProductSeeder::class);
        }
    }

    private function ensureTestUser(): void
    {
        if (User::query()->whereKey(1)->exists()) {
            return;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'User ID 1 is missing. Demo credentials are only seeded in local/testing; provision the required user explicitly.'
            );
        }

        $email = 'admin@test.com';
        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException(
                "Cannot create test user ID 1: email {$email} is already assigned to another user."
            );
        }

        try {
            User::unguarded(function () use ($email): void {
                User::query()->create([
                    'id' => 1,
                    'name' => 'Test User',
                    'email' => $email,
                    'password' => 'password',
                ]);
            });
        } catch (QueryException $exception) {
            if (User::query()->whereKey(1)->exists()) {
                return;
            }

            if (User::query()->where('email', $email)->exists()) {
                throw new RuntimeException(
                    "Cannot create test user ID 1: email {$email} was claimed by another user.",
                    previous: $exception
                );
            }

            throw $exception;
        }
    }
}

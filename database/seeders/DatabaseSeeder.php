<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Datos de referencia necesarios en cualquier entorno (idempotente).
 * No crea usuarios: usar `php artisan app:create-admin`.
 * Datos ficticios de demostración: `php artisan db:seed --class=DemoSeeder` (solo local).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('business.expense_categories') as $slug => $name) {
            ExpenseCategory::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        Category::firstOrCreate(['slug' => 'celulares'], ['name' => 'Celulares', 'is_serialized' => true, 'requires_imei' => true]);
        Category::firstOrCreate(['slug' => 'accesorios'], ['name' => 'Accesorios', 'is_serialized' => false, 'requires_imei' => false]);
    }
}

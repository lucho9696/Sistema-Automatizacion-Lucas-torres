<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * DATOS FICTICIOS DE DEMOSTRACIÓN. No representan precios, stock ni clientes reales.
 * Se niega a ejecutarse en producción.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder no se ejecuta en producción.');
        }
        $this->call(DatabaseSeeder::class);

        User::firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'Admin Demo', 'password' => Hash::make('demo-password'), 'role' => 'admin',
        ]);
        User::firstOrCreate(['email' => 'vendedor@example.com'], [
            'name' => 'Vendedor Demo', 'password' => Hash::make('demo-password'), 'role' => 'vendedor',
        ]);

        $phones = Category::where('slug', 'celulares')->first();
        $acc = Category::where('slug', 'accesorios')->first();

        $iphone = Product::firstOrCreate(['sku' => 'DEMO-IP15'], [
            'category_id' => $phones->id, 'brand' => 'Apple', 'name' => 'iPhone 15 (DEMO)', 'is_serialized' => true,
        ]);
        foreach ([['DEMO-IP15-128-BLK', '128 GB Negro', '128', 'negro'], ['DEMO-IP15-256-BLU', '256 GB Azul', '256', 'azul']] as [$sku, $name, $gb, $color]) {
            $iphone->variants()->firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'attributes' => ['storage_gb' => $gb, 'color' => $color, 'condition' => 'nuevo'],
            ]);
        }
        $case = Product::firstOrCreate(['sku' => 'DEMO-FUNDA'], [
            'category_id' => $acc->id, 'brand' => 'Genérica', 'name' => 'Funda silicona (DEMO)', 'is_serialized' => false,
        ]);
        $case->variants()->firstOrCreate(['sku' => 'DEMO-FUNDA-IP15'], ['name' => 'Para iPhone 15']);

        Supplier::firstOrCreate(['name' => 'Proveedor Miami (DEMO)'], ['country' => 'US']);
        Customer::firstOrCreate(['name' => 'Cliente Mayorista (DEMO)'], ['type' => 'mayorista', 'city' => 'Córdoba', 'source' => 'demo']);
    }
}

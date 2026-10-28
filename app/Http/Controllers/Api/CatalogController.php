<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function __construct(private Auditor $auditor) {}

    public function categories()
    {
        Gate::authorize('inventory.view');

        return Category::orderBy('name')->get();
    }

    public function storeCategory(Request $request)
    {
        Gate::authorize('catalog.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_serialized' => ['boolean'],
            'requires_imei' => ['boolean'],
        ]);
        $category = Category::create($data + ['slug' => Str::slug($data['name'])]);
        $this->auditor->record('catalogo.categoria_creada', $category, null, $category->toArray());

        return response()->json($category, 201);
    }

    public function products(Request $request)
    {
        Gate::authorize('inventory.view');

        return Product::query()
            ->with(['category', 'variants'])
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$term}%")->orWhere('sku', 'ilike', "%{$term}%")->orWhere('brand', 'ilike', "%{$term}%")))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function storeProduct(Request $request)
    {
        Gate::authorize('catalog.manage');
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['required', 'string', 'max:60', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
            'attributes' => ['nullable', 'array'],
            'is_serialized' => ['nullable', 'boolean'],
            'variants' => ['array'],
            'variants.*.sku' => ['required', 'string', 'max:60', 'distinct', 'unique:product_variants,sku'],
            'variants.*.name' => ['required', 'string', 'max:160'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.list_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'variants.*.price_currency' => ['nullable', Rule::in(config('business.currencies'))],
            'variants.*.wholesale_min_qty' => ['nullable', 'integer', 'min:1'],
        ]);

        $product = DB::transaction(function () use ($data) {
            $category = Category::findOrFail($data['category_id']);
            $product = Product::create([
                'category_id' => $category->id,
                'brand' => $data['brand'] ?? null,
                'name' => $data['name'],
                'sku' => $data['sku'],
                'description' => $data['description'] ?? null,
                'attributes' => $data['attributes'] ?? null,
                // Por defecto hereda de la categoría (celulares => serializado).
                'is_serialized' => $data['is_serialized'] ?? $category->is_serialized,
            ]);
            foreach ($data['variants'] ?? [] as $v) {
                $product->variants()->create($v);
            }
            $this->auditor->record('catalogo.producto_creado', $product, null, $product->toArray());

            return $product;
        });

        return response()->json($product->load('variants', 'category'), 201);
    }

    public function updateProduct(Request $request, Product $product)
    {
        Gate::authorize('catalog.manage');
        $data = $request->validate([
            'brand' => ['nullable', 'string', 'max:80'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'attributes' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $old = $product->only(array_keys($data));
        $product->update($data);
        $this->auditor->record('catalogo.producto_modificado', $product, $old, $data);

        return $product->load('variants');
    }

    public function storeVariant(Request $request, Product $product)
    {
        Gate::authorize('catalog.manage');
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', 'unique:product_variants,sku'],
            'name' => ['required', 'string', 'max:160'],
            'attributes' => ['nullable', 'array'],
            'list_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'price_currency' => ['nullable', Rule::in(config('business.currencies'))],
            'wholesale_min_qty' => ['nullable', 'integer', 'min:1'],
        ]);
        $variant = $product->variants()->create($data);
        $this->auditor->record('catalogo.variante_creada', $variant, null, $variant->toArray());

        return response()->json($variant, 201);
    }

    /** Cambiar precio de lista no altera ventas ya confirmadas (precio congelado en la línea). */
    public function updateVariant(Request $request, ProductVariant $variant)
    {
        Gate::authorize('catalog.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'attributes' => ['nullable', 'array'],
            'list_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'price_currency' => ['nullable', Rule::in(config('business.currencies'))],
            'wholesale_min_qty' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $old = $variant->only(array_keys($data));
        $variant->update($data);
        $this->auditor->record('catalogo.variante_modificada', $variant, $old, $data);

        return $variant;
    }

    public function stock(Request $request)
    {
        Gate::authorize('inventory.view');

        return ProductVariant::query()
            ->with('product:id,name,brand,sku,is_serialized,category_id')
            ->when($request->boolean('available_only'), fn ($q) => $q->whereRaw('stock_on_hand - stock_reserved - stock_held > 0'))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('sku', 'ilike', "%{$term}%")->orWhere('name', 'ilike', "%{$term}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', "%{$term}%"))))
            ->orderBy('sku')
            ->paginate(min((int) $request->query('per_page', 100), 500));
    }

    public function units(Request $request)
    {
        Gate::authorize('inventory.view');
        $request->validate(['status' => ['nullable', Rule::in(ProductUnit::STATUSES)]]);
        $units = ProductUnit::query()
            ->with('variant:id,sku,name')
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('imei') && Gate::allows('units.view-imei'), fn ($q) => $q->where(fn ($w) => $w
                ->where('imei', preg_replace('/\D/', '', $request->query('imei')))
                ->orWhere('imei2', preg_replace('/\D/', '', $request->query('imei')))
                ->orWhere('serial_number', $request->query('imei'))))
            ->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 100), 500));

        // IMEI/serie restringidos a roles autorizados (especificación §5.3).
        if (Gate::denies('units.view-imei')) {
            $units->getCollection()->each->makeHidden(['imei', 'imei2', 'serial_number']);
        }

        return $units;
    }
}

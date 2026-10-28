<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\InventoryService;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory, private Auditor $auditor) {}

    public function movements(Request $request)
    {
        Gate::authorize('inventory.view');

        return InventoryMovement::with('variant:id,sku,name')
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('product_variant_id', $v))
            ->orderByDesc('id')
            ->paginate(100);
    }

    /** Ajuste de stock para productos no serializados (conteo, rotura, faltante). */
    public function adjust(Request $request)
    {
        Gate::authorize('inventory.adjust');
        $data = $request->validate([
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'from_held' => ['boolean'],
        ]);

        return DB::transaction(function () use ($data) {
            $variant = $this->inventory->lockVariant($data['product_variant_id']);
            if ($variant->product->is_serialized) {
                throw new BusinessRuleException('Para productos serializados cambie el estado de la unidad.', 'product_variant_id');
            }
            $this->inventory->adjust($variant, $data['delta'], $data['reason'], (bool) ($data['from_held'] ?? false));

            return $variant->fresh();
        });
    }

    /**
     * Cambio de estado de una unidad serializada fuera del flujo de venta:
     *  en_revision|devuelto|en_garantia -> disponible (libera retención)
     *  disponible -> en_revision|en_garantia (retiene)
     *  disponible|en_revision|devuelto|en_garantia -> perdido (baja de stock)
     */
    public function changeUnitStatus(Request $request, ProductUnit $unit)
    {
        Gate::authorize('inventory.adjust');
        $data = $request->validate([
            'status' => ['required', Rule::in(['disponible', 'en_revision', 'en_garantia', 'perdido'])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($unit, $data) {
            $unit = ProductUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $variant = $this->inventory->lockVariant($unit->product_variant_id);
            $held = ['en_revision', 'devuelto', 'en_garantia'];
            $from = $unit->status;
            $to = $data['status'];

            if (in_array($from, $held, true) && $to === 'disponible') {
                $this->inventory->releaseHold($variant, 1, $data['reason'], $unit);
            } elseif ($from === 'disponible' && in_array($to, ['en_revision', 'en_garantia'], true)) {
                $this->inventory->hold($variant, 1, $data['reason'], $unit);
            } elseif (in_array($from, $held, true) && in_array($to, $held, true)) {
                // Sigue retenida; solo cambia la etiqueta.
            } elseif ($to === 'perdido' && $from === 'disponible') {
                $this->inventory->adjust($variant, -1, $data['reason'], false, $unit);
            } elseif ($to === 'perdido' && in_array($from, $held, true)) {
                $this->inventory->adjust($variant, -1, $data['reason'], true, $unit);
            } else {
                throw new BusinessRuleException("Transición no permitida: {$from} → {$to}.", 'status');
            }
            $unit->update(['status' => $to]);
            $this->auditor->record('inventario.unidad_estado', $unit, ['status' => $from], ['status' => $to], $data['reason']);

            return $unit;
        });
    }
}

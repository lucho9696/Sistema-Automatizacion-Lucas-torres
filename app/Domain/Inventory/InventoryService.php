<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Models\InventoryMovement;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Único punto que modifica saldos de stock. Cada cambio deja un movimiento
 * (libro mayor) y se ejecuta con la variante bloqueada (SELECT ... FOR UPDATE).
 *
 * Saldos por variante:
 *  - on_hand:  unidades físicamente en poder del negocio.
 *  - reserved: comprometidas por ventas confirmadas no entregadas.
 *  - held:     retenidas (en revisión / devueltas), no vendibles.
 *  - disponible = on_hand - reserved - held.
 */
class InventoryService
{
    public function __construct(private Auditor $auditor) {}

    public function lockVariant(int $variantId): ProductVariant
    {
        return ProductVariant::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();
    }

    /** Ingreso por recepción de compra. */
    public function receive(ProductVariant $variant, int $qty, string $unitCostBase, ?Model $reference, bool $held = false, ?ProductUnit $unit = null): void
    {
        $this->assertTransaction();
        // Costo promedio ponderado (moneda base), se actualiza antes de sumar stock.
        $currentQty = max($variant->stock_on_hand, 0);
        $newQty = $currentQty + $qty;
        if ($newQty > 0) {
            $value = Money::of($variant->avg_cost_base)->multipliedBy($currentQty)
                ->plus(Money::of($unitCostBase)->multipliedBy($qty));
            $variant->avg_cost_base = Money::div($value, (string) $newQty, Money::UNIT_SCALE);
        }

        $this->apply($variant, 'entrada', $qty, 0, $held ? $qty : 0, $reference, $unit, $unitCostBase);
    }

    public function reserve(ProductVariant $variant, int $qty, ?Model $reference, ?ProductUnit $unit = null): void
    {
        $this->assertTransaction();
        if (! config('business.inventory.allow_negative_stock') && $variant->stock_available < $qty) {
            throw new BusinessRuleException(
                "Stock insuficiente para {$variant->sku}: disponible {$variant->stock_available}, solicitado {$qty}."
            );
        }
        $this->apply($variant, 'reserva', 0, $qty, 0, $reference, $unit);
    }

    public function release(ProductVariant $variant, int $qty, ?Model $reference, ?string $reason = null, ?ProductUnit $unit = null): void
    {
        $this->apply($variant, 'liberacion', 0, -$qty, 0, $reference, $unit, null, $reason);
    }

    /** Salida definitiva (entrega): consume la reserva. */
    public function ship(ProductVariant $variant, int $qty, ?Model $reference, ?ProductUnit $unit = null, ?string $unitCostBase = null): void
    {
        $this->apply($variant, 'salida', -$qty, -$qty, 0, $reference, $unit, $unitCostBase);
    }

    /** Reingreso por devolución: vuelve como retenido hasta revisión. */
    public function returnIn(ProductVariant $variant, int $qty, ?Model $reference, ?string $reason, ?ProductUnit $unit = null, ?string $unitCostBase = null): void
    {
        $this->apply($variant, 'devolucion', $qty, 0, $qty, $reference, $unit, $unitCostBase, $reason);
    }

    /** Retiene unidades disponibles (revisión, garantía): dejan de ser vendibles. */
    public function hold(ProductVariant $variant, int $qty, ?string $reason, ?ProductUnit $unit = null): void
    {
        if ($variant->stock_available < $qty) {
            throw new BusinessRuleException("No hay {$qty} unidades disponibles para retener en {$variant->sku}.");
        }
        $this->apply($variant, 'retencion', 0, 0, $qty, null, $unit, null, $reason);
    }

    /** Libera unidades retenidas (revisión aprobada) para la venta. */
    public function releaseHold(ProductVariant $variant, int $qty, ?string $reason, ?ProductUnit $unit = null): void
    {
        if ($variant->stock_held < $qty) {
            throw new BusinessRuleException("No hay {$qty} unidades retenidas para {$variant->sku}.");
        }
        $this->apply($variant, 'liberacion_retencion', 0, 0, -$qty, null, $unit, null, $reason);
    }

    /** Ajuste manual auditado (faltante, rotura, conteo). delta negativo = baja. */
    public function adjust(ProductVariant $variant, int $delta, string $reason, bool $fromHeld = false, ?ProductUnit $unit = null): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleException('El ajuste de inventario requiere un motivo.', 'reason');
        }
        $heldDelta = $fromHeld ? $delta : 0;
        if ($fromHeld && $delta < 0 && $variant->stock_held < -$delta) {
            throw new BusinessRuleException('No hay suficientes unidades retenidas para dar de baja.');
        }
        if (! $fromHeld && $delta < 0 && ! config('business.inventory.allow_negative_stock') && $variant->stock_available < -$delta) {
            throw new BusinessRuleException("El ajuste dejaría stock disponible negativo en {$variant->sku}.");
        }
        $this->apply($variant, 'ajuste', $delta, 0, $heldDelta, null, $unit, null, $reason);
    }

    private function apply(ProductVariant $variant, string $type, int $onHand, int $reserved, int $held, ?Model $reference, ?ProductUnit $unit, ?string $unitCostBase = null, ?string $reason = null): void
    {
        $this->assertTransaction();
        $old = $variant->only(['stock_on_hand', 'stock_reserved', 'stock_held']);

        $variant->stock_on_hand += $onHand;
        $variant->stock_reserved += $reserved;
        $variant->stock_held += $held;

        if ($variant->stock_reserved < 0 || $variant->stock_held < 0) {
            throw new BusinessRuleException("Movimiento inválido para {$variant->sku}: saldo reservado/retenido negativo.");
        }
        if (! config('business.inventory.allow_negative_stock') && $variant->stock_on_hand < 0) {
            throw new BusinessRuleException("Stock negativo no permitido para {$variant->sku}.");
        }
        $variant->save();

        InventoryMovement::create([
            'product_variant_id' => $variant->id,
            'product_unit_id' => $unit?->id,
            'type' => $type,
            'on_hand_delta' => $onHand,
            'reserved_delta' => $reserved,
            'held_delta' => $held,
            'unit_cost_base' => $unitCostBase,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'user_id' => Auth::id(),
            'reason' => $reason,
        ]);

        if ($type === 'ajuste') {
            $this->auditor->record('inventario.ajuste', $variant, $old, $variant->only(['stock_on_hand', 'stock_reserved', 'stock_held']), $reason);
        }
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Las operaciones de inventario deben ejecutarse dentro de una transacción.');
        }
    }
}

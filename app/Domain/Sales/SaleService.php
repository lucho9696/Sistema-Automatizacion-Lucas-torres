<?php

namespace App\Domain\Sales;

use App\Domain\Finance\CashService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Domain\Shared\Currency;
use App\Models\Payment;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo de vida de una venta:
 *   borrador (cotización) -> confirmada (precio y costo congelados, stock reservado)
 *   confirmada -> entregada (salida definitiva de stock)
 *   borrador|confirmada -> cancelada (libera reserva; exige no tener cobros vigentes)
 *   entregada -> devuelta (stock vuelve como retenido; los cobros se anulan aparte)
 * El estado de cobro (pendiente/parcial/pagado) es independiente y se deriva de los pagos.
 */
class SaleService
{
    public function __construct(
        private InventoryService $inventory,
        private CashService $cash,
        private Auditor $auditor,
    ) {}

    public function createDraft(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $currency = Currency::assertAllowed($data['currency']);
            $rate = Currency::rateFor($currency, $data['exchange_rate'] ?? null);
            $saleDate = $data['sale_date'] ?? BusinessDate::today();
            $terms = $data['payment_terms'] ?? 'contado';

            $sale = Sale::create([
                'number' => 'TMP-'.Str::random(12),
                'customer_id' => $data['customer_id'],
                'seller_id' => Auth::id(),
                'status' => 'borrador',
                'currency' => $currency,
                'exchange_rate' => $rate,
                'exchange_rate_date' => $data['exchange_rate_date'] ?? $saleDate,
                'exchange_rate_source' => $data['exchange_rate_source'] ?? null,
                'sale_date' => $saleDate,
                'payment_terms' => $terms,
                'due_date' => $terms === 'credito' ? ($data['due_date'] ?? null) : $saleDate,
                'quote_valid_until' => $data['quote_valid_until'] ?? null,
                'discount' => Money::round($data['discount'] ?? '0'),
                'notes' => $data['notes'] ?? null,
            ]);
            if ($terms === 'credito' && empty($data['due_date'])) {
                throw new BusinessRuleException('Una venta a crédito requiere fecha de vencimiento.', 'due_date');
            }
            $sale->number = sprintf('V-%06d', $sale->id);

            $subtotal = '0';
            foreach ($data['items'] as $i => $line) {
                $variant = ProductVariant::with('product')->findOrFail($line['product_variant_id']);
                $unitIds = array_values(array_unique($line['unit_ids'] ?? []));
                $qty = (int) ($line['quantity'] ?? count($unitIds));
                if ($qty < 1) {
                    throw new BusinessRuleException('La cantidad debe ser al menos 1.', "items.{$i}.quantity");
                }
                if ($unitIds && ! $variant->product->is_serialized) {
                    throw new BusinessRuleException('Solo los productos serializados admiten unidades (IMEI).', "items.{$i}.unit_ids");
                }
                if ($unitIds && count($unitIds) !== $qty) {
                    throw new BusinessRuleException('La cantidad no coincide con las unidades seleccionadas.', "items.{$i}.unit_ids");
                }
                $lineTotal = Money::sub(Money::mul($qty, $line['unit_price']), $line['discount'] ?? '0');
                if (Money::isNegative($lineTotal)) {
                    throw new BusinessRuleException('El descuento supera el valor de la línea.', "items.{$i}.discount");
                }
                $item = $sale->items()->create([
                    'product_variant_id' => $variant->id,
                    'description' => trim($variant->product->name.' '.$variant->name),
                    'quantity' => $qty,
                    'unit_price' => Money::round($line['unit_price']),
                    'discount' => Money::round($line['discount'] ?? '0'),
                    'line_total' => $lineTotal,
                ]);
                foreach ($unitIds as $unitId) {
                    $unit = ProductUnit::findOrFail($unitId);
                    if ($unit->product_variant_id !== $variant->id) {
                        throw new BusinessRuleException("La unidad {$unitId} no pertenece a la variante {$variant->sku}.", "items.{$i}.unit_ids");
                    }
                    $item->itemUnits()->create(['product_unit_id' => $unitId]);
                }
                $subtotal = Money::add($subtotal, $lineTotal);
            }
            $total = Money::sub($subtotal, $sale->discount);
            if (Money::isNegative($total)) {
                throw new BusinessRuleException('El descuento supera el total de la venta.', 'discount');
            }
            $sale->subtotal = $subtotal;
            $sale->total = $total;
            $sale->total_base = Money::toBase($total, $rate);
            $sale->save();

            $this->auditor->record('venta.borrador', $sale, null, $sale->only(['number', 'customer_id', 'currency', 'total']));

            return $sale;
        });
    }

    /** Confirma: congela costo, reserva stock (y unidades). Todo o nada. */
    public function confirm(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale) {
            $sale = $this->lock($sale);
            if ($sale->status !== 'borrador') {
                throw new BusinessRuleException("Solo se confirma una venta en borrador (estado actual: {$sale->status}).");
            }
            $sale->load('items.itemUnits');
            $costTotal = '0';
            // Orden determinista de bloqueo para evitar deadlocks entre ventas concurrentes.
            foreach ($sale->items->sortBy('product_variant_id') as $item) {
                $variant = $this->inventory->lockVariant($item->product_variant_id);
                $variant->load('product');
                if ($variant->product->is_serialized) {
                    $lineCost = $this->reserveUnits($item, $variant, $sale);
                    $unitCost = Money::div($lineCost, (string) $item->quantity, Money::UNIT_SCALE);
                } else {
                    $this->inventory->reserve($variant, $item->quantity, $sale);
                    $unitCost = Money::round((string) $variant->avg_cost_base, Money::UNIT_SCALE);
                    $lineCost = Money::mul($unitCost, (string) $item->quantity);
                }
                $item->update(['unit_cost_base' => $unitCost, 'line_cost_base' => $lineCost]);
                $costTotal = Money::add($costTotal, $lineCost);
            }

            $sale->update([
                'status' => 'confirmada',
                'confirmed_at' => now(),
                'cost_total_base' => $costTotal,
            ]);
            $this->auditor->record('venta.confirmada', $sale, ['status' => 'borrador'], $sale->only(['status', 'total', 'total_base', 'cost_total_base']));

            return $sale;
        });
    }

    private function reserveUnits(SaleItem $item, ProductVariant $variant, Sale $sale): string
    {
        $unitIds = $item->itemUnits->pluck('product_unit_id')->all();
        if ($unitIds === []) {
            // Asignación automática FIFO de unidades disponibles.
            $units = ProductUnit::query()
                ->where('product_variant_id', $variant->id)
                ->where('status', 'disponible')
                ->orderBy('id')
                ->limit($item->quantity)
                ->lockForUpdate()
                ->get();
        } else {
            $units = ProductUnit::query()->whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->get();
        }
        if ($units->count() < $item->quantity) {
            throw new BusinessRuleException("Stock insuficiente para {$variant->sku}: no hay {$item->quantity} unidades disponibles.");
        }
        $lineCost = '0';
        foreach ($units as $unit) {
            if ($unit->status !== 'disponible') {
                throw new BusinessRuleException("La unidad {$unit->id} no está disponible (estado: {$unit->status}).");
            }
            $this->inventory->reserve($variant, 1, $sale, $unit);
            $unit->update(['status' => 'reservado']);
            $item->itemUnits()->updateOrCreate(
                ['product_unit_id' => $unit->id],
                ['unit_cost_base' => $unit->unit_cost_base]
            );
            $lineCost = Money::add($lineCost, (string) $unit->unit_cost_base);
        }

        return $lineCost;
    }

    public function deliver(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale) {
            $sale = $this->lock($sale);
            if ($sale->status !== 'confirmada') {
                throw new BusinessRuleException('Solo se entrega una venta confirmada.');
            }
            $sale->load('items.itemUnits.unit');
            foreach ($sale->items->sortBy('product_variant_id') as $item) {
                $variant = $this->inventory->lockVariant($item->product_variant_id);
                if ($item->itemUnits->isNotEmpty()) {
                    foreach ($item->itemUnits as $iu) {
                        $this->inventory->ship($variant, 1, $sale, $iu->unit, (string) $iu->unit_cost_base);
                        $iu->unit->update(['status' => 'vendido']);
                    }
                } else {
                    $this->inventory->ship($variant, $item->quantity, $sale, null, (string) $item->unit_cost_base);
                }
            }
            $sale->update(['status' => 'entregada', 'delivered_at' => now()]);
            $this->auditor->record('venta.entregada', $sale, ['status' => 'confirmada'], ['status' => 'entregada']);

            return $sale;
        });
    }

    public function cancel(Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $sale = $this->lock($sale);
            if (! in_array($sale->status, ['borrador', 'confirmada'], true)) {
                throw new BusinessRuleException('Solo se cancela una venta en borrador o confirmada. Una entregada se devuelve.');
            }
            if (Payment::where('sale_id', $sale->id)->where('status', 'confirmado')->exists()) {
                throw new BusinessRuleException('La venta tiene cobros vigentes: anúlelos o reintegre el dinero antes de cancelar.');
            }
            $old = $sale->status;
            if ($old === 'confirmada') {
                $sale->load('items.itemUnits.unit');
                foreach ($sale->items->sortBy('product_variant_id') as $item) {
                    $variant = $this->inventory->lockVariant($item->product_variant_id);
                    if ($item->itemUnits->isNotEmpty()) {
                        foreach ($item->itemUnits as $iu) {
                            $this->inventory->release($variant, 1, $sale, $reason, $iu->unit);
                            $iu->unit->update(['status' => 'disponible']);
                        }
                    } else {
                        $this->inventory->release($variant, $item->quantity, $sale, $reason);
                    }
                }
            }
            $sale->update(['status' => 'cancelada', 'cancelled_at' => now(), 'status_reason' => $reason]);
            $this->auditor->record('venta.cancelada', $sale, ['status' => $old], ['status' => 'cancelada'], $reason);

            return $sale;
        });
    }

    /** Devolución total de una venta entregada. El reintegro de dinero se registra anulando cobros. */
    public function returnSale(Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $sale = $this->lock($sale);
            if ($sale->status !== 'entregada') {
                throw new BusinessRuleException('Solo se devuelve una venta entregada. Una confirmada se cancela.');
            }
            $sale->load('items.itemUnits.unit');
            foreach ($sale->items->sortBy('product_variant_id') as $item) {
                $variant = $this->inventory->lockVariant($item->product_variant_id);
                if ($item->itemUnits->isNotEmpty()) {
                    foreach ($item->itemUnits as $iu) {
                        $this->inventory->returnIn($variant, 1, $sale, $reason, $iu->unit, (string) $iu->unit_cost_base);
                        $iu->unit->update(['status' => 'devuelto']);
                    }
                } else {
                    $this->inventory->returnIn($variant, $item->quantity, $sale, $reason, null, (string) $item->unit_cost_base);
                }
            }
            $sale->update(['status' => 'devuelta', 'returned_at' => now(), 'status_reason' => $reason]);
            $this->auditor->record('venta.devuelta', $sale, ['status' => 'entregada'], ['status' => 'devuelta'], $reason);

            return $sale;
        });
    }

    private function lock(Sale $sale): Sale
    {
        return Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
    }
}

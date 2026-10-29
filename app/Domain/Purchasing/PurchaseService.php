<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\InventoryService;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Domain\Shared\Currency;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private CostAllocator $allocator,
        private InventoryService $inventory,
        private Auditor $auditor,
    ) {}

    /** Crea un lote de compra en borrador con ítems y costos atribuibles. */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $currency = Currency::assertAllowed($data['currency']);
            $rate = Currency::rateFor($currency, $data['exchange_rate'] ?? null);

            $po = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'created_by' => Auth::id(),
                'reference' => $data['reference'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'currency' => $currency,
                'exchange_rate' => $rate,
                'exchange_rate_date' => $data['exchange_rate_date'] ?? $data['purchase_date'],
                'exchange_rate_source' => $data['exchange_rate_source'] ?? null,
                'status' => 'draft',
                'allocation_method' => $data['allocation_method'] ?? config('business.purchases.allocation_method'),
                'notes' => $data['notes'] ?? null,
            ]);

            $goods = '0';
            $goodsBase = '0';
            foreach ($data['items'] as $i => $item) {
                $lineTotal = Money::sub(Money::mul($item['quantity'], $item['unit_cost']), $item['discount'] ?? '0');
                if (Money::isNegative($lineTotal)) {
                    throw new BusinessRuleException('El descuento supera el valor de la línea.', "items.{$i}.discount");
                }
                $lineBase = Money::toBase($lineTotal, $rate);
                $po->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => Money::round($item['unit_cost'], Money::UNIT_SCALE),
                    'discount' => Money::round($item['discount'] ?? '0'),
                    'line_total' => $lineTotal,
                    'line_total_base' => $lineBase,
                ]);
                $goods = Money::add($goods, $lineTotal);
                $goodsBase = Money::add($goodsBase, $lineBase);
            }

            $extraBase = '0';
            foreach ($data['costs'] ?? [] as $i => $cost) {
                $costCurrency = Currency::assertAllowed($cost['currency'] ?? $currency, "costs.{$i}.currency");
                $costRate = $costCurrency === $currency && ! isset($cost['exchange_rate'])
                    ? $rate
                    : Currency::rateFor($costCurrency, $cost['exchange_rate'] ?? null, "costs.{$i}.exchange_rate");
                $amountBase = Money::toBase($cost['amount'], $costRate);
                $po->costs()->create([
                    'type' => $cost['type'],
                    'description' => $cost['description'] ?? null,
                    'amount' => Money::round($cost['amount']),
                    'currency' => $costCurrency,
                    'exchange_rate' => $costRate,
                    'amount_base' => $amountBase,
                ]);
                $extraBase = Money::add($extraBase, $amountBase);
            }

            $po->update([
                'goods_total' => $goods,
                'goods_total_base' => $goodsBase,
                'extra_costs_base' => $extraBase,
                'landed_total_base' => Money::add($goodsBase, $extraBase),
            ]);

            $this->auditor->record('compra.creada', $po, null, $po->only(['supplier_id', 'currency', 'exchange_rate', 'goods_total', 'extra_costs_base']));

            return $po->load('items', 'costs');
        });
    }

    /**
     * Recepción del lote (única, MVP): registra recibidas/dañadas/faltantes por línea,
     * distribuye costos, crea unidades serializadas y genera las entradas de stock.
     *
     * @param  array<int, array{item_id:int, quantity_received:int, quantity_damaged?:int, units?:array<int,array<string,mixed>>}>  $lines
     */
    public function receive(PurchaseOrder $po, array $lines, bool $holdForInspection = false): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $lines, $holdForInspection) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== 'draft') {
                throw new BusinessRuleException('La compra ya fue recibida o cancelada.');
            }
            $po->load('items.variant.product');
            $byItem = collect($lines)->keyBy('item_id');

            $allocLines = [];
            foreach ($po->items as $item) {
                $line = $byItem->get($item->id);
                if (! $line) {
                    throw new BusinessRuleException("Falta informar la recepción del ítem {$item->id}.", 'lines');
                }
                $received = (int) $line['quantity_received'];
                $damaged = (int) ($line['quantity_damaged'] ?? 0);
                if ($received > $item->quantity) {
                    throw new BusinessRuleException("Se recibieron más unidades que las compradas en el ítem {$item->id}.");
                }
                if ($damaged > $received) {
                    throw new BusinessRuleException("Las dañadas no pueden superar las recibidas en el ítem {$item->id}.");
                }
                $valid = $received - $damaged;
                if (! $item->variant->product->is_serialized && ! empty($line['units'])) {
                    throw new BusinessRuleException("El ítem {$item->id} no es serializado: no informe IMEI/serie.");
                }
                if ($item->variant->product->is_serialized) {
                    $units = $line['units'] ?? [];
                    if (count($units) !== $valid) {
                        throw new BusinessRuleException(
                            "El ítem {$item->id} es serializado: informe IMEI/serie de las {$valid} unidades válidas (recibió ".count($units).').'
                        );
                    }
                }
                $item->quantity_received = $received;
                $item->quantity_damaged = $damaged;
                $item->quantity_missing = $item->quantity - $received;
                $allocLines[$item->id] = [
                    'line_total_base' => (string) $item->line_total_base,
                    'quantity' => $item->quantity,
                    'valid_quantity' => $valid,
                ];
            }

            $result = $this->allocator->allocate($allocLines, (string) $po->extra_costs_base, $po->allocation_method);

            foreach ($po->items as $item) {
                $r = $result['lines'][$item->id];
                $item->allocated_costs_base = $r['allocated_costs_base'];
                $item->landed_line_total_base = $r['landed_line_total_base'];
                $item->landed_unit_cost_base = $r['landed_unit_cost_base'] ?? '0';
                $item->save();

                $valid = $r['valid_quantity'];
                if ($valid === 0) {
                    continue;
                }
                $variant = $this->inventory->lockVariant($item->product_variant_id);
                if ($item->variant->product->is_serialized) {
                    $this->receiveSerialized($variant, $item, $byItem->get($item->id)['units'], $r['landed_unit_cost_base'], $po, $holdForInspection);
                } else {
                    $this->inventory->receive($variant, $valid, $r['landed_unit_cost_base'], $po, $holdForInspection);
                }
            }

            $po->update([
                'status' => 'received',
                'received_at' => now(),
                'allocation_snapshot' => $result,
            ]);
            $this->auditor->record('compra.recibida', $po, null, ['allocation' => $result]);

            return $po->fresh(['items.units', 'costs', 'supplier']);
        });
    }

    private function receiveSerialized(ProductVariant $variant, $item, array $units, string $unitCost, PurchaseOrder $po, bool $hold): void
    {
        $requiresImei = (bool) $item->variant->product->category->requires_imei;
        foreach ($units as $i => $u) {
            $imei = isset($u['imei']) ? preg_replace('/\D/', '', $u['imei']) : null;
            if ($requiresImei && ! $imei) {
                throw new BusinessRuleException("La unidad {$i} del ítem {$item->id} requiere IMEI.");
            }
            if (! $imei && empty($u['serial_number'])) {
                throw new BusinessRuleException("La unidad {$i} del ítem {$item->id} necesita IMEI o número de serie.");
            }
            foreach (['imei' => $imei, 'imei2' => $u['imei2'] ?? null, 'serial_number' => $u['serial_number'] ?? null] as $field => $value) {
                if ($value && ProductUnit::where($field, $value)->exists()) {
                    throw new BusinessRuleException("Ya existe una unidad con {$field} {$value}.", "lines.units.{$i}.{$field}");
                }
            }
            $unit = ProductUnit::create([
                'product_variant_id' => $variant->id,
                'purchase_order_item_id' => $item->id,
                'imei' => $imei,
                'imei2' => isset($u['imei2']) ? preg_replace('/\D/', '', $u['imei2']) : null,
                'serial_number' => $u['serial_number'] ?? null,
                'condition' => $u['condition'] ?? ($variant->attributes['condition'] ?? null),
                'battery_health' => $u['battery_health'] ?? null,
                'location' => $u['location'] ?? null,
                'status' => $hold ? 'en_revision' : 'disponible',
                'unit_cost_base' => $unitCost,
                'inspection_notes' => $u['inspection_notes'] ?? null,
            ]);
            $this->inventory->receive($variant, 1, $unitCost, $po, $hold, $unit);
        }
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== 'draft') {
                throw new BusinessRuleException('Solo se puede cancelar una compra en borrador. Para una recibida, registre ajustes/devoluciones.');
            }
            $po->update(['status' => 'cancelled', 'notes' => trim(($po->notes ?? '')."\nCancelada: {$reason}")]);
            $this->auditor->record('compra.cancelada', $po, null, null, $reason);

            return $po;
        });
    }
}

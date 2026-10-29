<?php

namespace App\Domain\Purchasing;

use App\Domain\Shared\BusinessRuleException;
use App\Support\Money;

/**
 * Distribuye los costos atribuibles de una compra (flete, seguro, aranceles...)
 * entre las líneas y calcula el costo unitario puesto en destino (moneda base).
 *
 *   costo puesto en destino de la línea = valor de la línea (base) + costos asignados
 *   costo unitario = costo puesto en destino de la línea / unidades válidas
 *   unidades válidas = recibidas - dañadas
 *
 * Funciones puras: el resultado se guarda completo en el lote (allocation_snapshot).
 */
class CostAllocator
{
    public const METHODS = ['value', 'quantity'];

    /**
     * @param  array<int|string, array{line_total_base: string, quantity: int, valid_quantity: int}>  $lines
     * @return array{method: string, extra_costs_base: string, goods_total_base: string, landed_total_base: string, lines: array<int|string, array<string, mixed>>, non_inventoriable_base: string, rounding_difference_base: string}
     */
    public function allocate(array $lines, string $extraCostsBase, string $method): array
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new BusinessRuleException("Método de distribución desconocido: {$method}.", 'allocation_method');
        }

        $weights = [];
        foreach ($lines as $key => $line) {
            $weights[$key] = $method === 'value' ? $line['line_total_base'] : (string) $line['quantity'];
        }
        $allocated = Money::allocate($extraCostsBase, $weights);

        $result = [];
        $goods = '0';
        $landedInInventory = '0';
        $nonInventoriable = '0';
        $roundingDiff = '0';
        foreach ($lines as $key => $line) {
            $landedLine = Money::add($line['line_total_base'], $allocated[$key]);
            $goods = Money::add($goods, $line['line_total_base']);
            $unitCost = null;
            if ($line['valid_quantity'] > 0) {
                $unitCost = Money::div($landedLine, (string) $line['valid_quantity'], Money::UNIT_SCALE);
                $inventoried = Money::mul($unitCost, (string) $line['valid_quantity']);
                $landedInInventory = Money::add($landedInInventory, $inventoried);
                $roundingDiff = Money::add($roundingDiff, Money::sub($landedLine, $inventoried));
            } else {
                // Sin unidades válidas: el costo no puede asignarse a stock.
                $nonInventoriable = Money::add($nonInventoriable, $landedLine);
            }
            $result[$key] = [
                'weight' => $weights[$key],
                'line_total_base' => Money::round($line['line_total_base']),
                'allocated_costs_base' => $allocated[$key],
                'landed_line_total_base' => $landedLine,
                'quantity' => $line['quantity'],
                'valid_quantity' => $line['valid_quantity'],
                'landed_unit_cost_base' => $unitCost,
            ];
        }

        return [
            'method' => $method,
            'extra_costs_base' => Money::round($extraCostsBase),
            'goods_total_base' => $goods,
            'landed_total_base' => Money::add($goods, $extraCostsBase),
            'landed_in_inventory_base' => $landedInInventory,
            'non_inventoriable_base' => $nonInventoriable,
            'rounding_difference_base' => $roundingDiff,
            'lines' => $result,
        ];
    }
}

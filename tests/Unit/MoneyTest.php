<?php

namespace Tests\Unit;

use App\Domain\Purchasing\CostAllocator;
use App\Support\Money;
use App\Support\Phone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_arithmetic_has_no_float_errors(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('1000000.01', Money::add('999999.99', '0.02'));
        $this->assertSame('1100.00', Money::toBase('1.00', '1100.000000'));
        $this->assertSame('0.3333', Money::div('1', '3', Money::UNIT_SCALE));
    }

    public function test_rejects_floats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(0.1);
    }

    public function test_allocation_always_sums_exactly_to_total(): void
    {
        $this->assertSame(['33.34', '33.33', '33.33'], Money::allocate('100.00', ['1', '1', '1']));
        $parts = Money::allocate('401500.00', ['2100000', '1500000', '50000']);
        $this->assertSame(['231000.00', '165000.00', '5500.00'], $parts);

        $odd = Money::allocate('0.05', ['3', '3', '3', '1']);
        $this->assertSame('0.05', Money::add(...$odd));
    }

    public function test_allocation_with_zero_weights_splits_evenly(): void
    {
        $this->assertSame(['5.00', '5.00'], Money::allocate('10', ['0', '0']));
    }

    public function test_cost_allocator_by_value_and_quantity(): void
    {
        $lines = [
            'a' => ['line_total_base' => '2100000.00', 'quantity' => 3, 'valid_quantity' => 3],
            'b' => ['line_total_base' => '1500000.00', 'quantity' => 2, 'valid_quantity' => 2],
            'c' => ['line_total_base' => '50000.00', 'quantity' => 10, 'valid_quantity' => 10],
        ];
        $byValue = (new CostAllocator)->allocate($lines, '401500.00', 'value');
        $this->assertSame('777000.0000', $byValue['lines']['a']['landed_unit_cost_base']);
        $this->assertSame('832500.0000', $byValue['lines']['b']['landed_unit_cost_base']);
        $this->assertSame('5550.0000', $byValue['lines']['c']['landed_unit_cost_base']);
        $this->assertSame('4051500.00', $byValue['landed_total_base']);
        $this->assertSame('0.00', $byValue['rounding_difference_base']);

        $byQty = (new CostAllocator)->allocate($lines, '401500.00', 'quantity');
        // 401.500 / 15 unidades = 26.766,67 por unidad (mayor resto en la primera línea)
        $this->assertSame('80300.00', $byQty['lines']['a']['allocated_costs_base']);
        $this->assertSame('401500.00', Money::add(...array_column($byQty['lines'], 'allocated_costs_base')));
    }

    public function test_damaged_units_raise_unit_cost_of_valid_ones(): void
    {
        $r = (new CostAllocator)->allocate(
            ['x' => ['line_total_base' => '1000.00', 'quantity' => 4, 'valid_quantity' => 3]],
            '200.00', 'value'
        );
        $this->assertSame('400.0000', $r['lines']['x']['landed_unit_cost_base']);

        $none = (new CostAllocator)->allocate(['x' => ['line_total_base' => '1000.00', 'quantity' => 1, 'valid_quantity' => 0]], '0', 'value');
        $this->assertSame('1000.00', $none['non_inventoriable_base']);
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('+543511234567', Phone::normalize('0351 123-4567'));
        $this->assertSame('+543511234567', Phone::normalize('+54 351 1234567'));
        $this->assertSame('+13055550100', Phone::normalize('+1 (305) 555-0100'));
        $this->assertNull(Phone::normalize('abc'));
    }
}

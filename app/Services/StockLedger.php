<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    public function milliUnits(string|int|float $quantity): int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/D', (string) $quantity, $matches)) {
            throw ValidationException::withMessages([
                'quantity' => 'Informe uma quantidade válida com até três casas decimais.',
            ]);
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    public function format(int $milliUnits): string
    {
        $absolute = abs($milliUnits);
        $whole = intdiv($absolute, 1000);
        $fraction = $absolute % 1000;
        $sign = $milliUnits < 0 ? '-' : '';

        return $sign.sprintf('%d.%03d', $whole, $fraction);
    }

    public function record(
        Product|Supply $item,
        int $delta,
        string $type,
        string $reason,
        User $user,
        ?int $checkoutId = null,
        ?int $costTotalCents = null,
    ): StockMovement {
        $current = $this->milliUnits((string) ($item->stock_quantity ?? '0'));
        $balance = $current + $delta;

        if ($balance < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'A quantidade informada excede o estoque disponível.',
            ]);
        }

        if ($item instanceof Product && $balance % 1000 !== 0) {
            throw ValidationException::withMessages([
                'quantity' => 'O estoque de produtos comercializáveis deve ser inteiro.',
            ]);
        }

        $averageCost = $item->average_cost_milli_cents;
        if ($type === 'entry') {
            if ($delta < 1 || $costTotalCents === null || $costTotalCents < 0) {
                throw ValidationException::withMessages([
                    'total_cost' => 'Informe o custo total da entrada do estoque.',
                ]);
            }

            $purchaseUnitCost = $this->roundedDivision($costTotalCents * 1_000_000, $delta);
            if ($current === 0 || $averageCost !== null) {
                [$previousShare, $previousRemainder] = $this->multiplyDivide(
                    (int) ($averageCost ?? 0),
                    $current,
                    $balance,
                );
                [$purchaseShare, $purchaseRemainder] = $this->multiplyDivide(
                    $purchaseUnitCost,
                    $delta,
                    $balance,
                );
                $averageCost = $previousShare + $purchaseShare;
                $remainder = $previousRemainder + $purchaseRemainder;
                if ($remainder >= intdiv($balance, 2) + ($balance % 2)) {
                    $averageCost++;
                }
            }
            $movementCost = $costTotalCents;
        } else {
            $movementCost = $averageCost === null
                ? null
                : $this->costForQuantity(abs($delta), $averageCost);
        }

        $item->stock_quantity = $item instanceof Product
            ? intdiv($balance, 1000)
            : $this->format($balance);
        $item->average_cost_milli_cents = $averageCost;
        $item->save();

        return $item->stockMovements()->create([
            'barbershop_id' => $item->barbershop_id,
            'user_id' => $user->id,
            'appointment_checkout_id' => $checkoutId,
            'stockable_name' => $item->name,
            'unit' => $item instanceof Supply ? $item->unit : 'un',
            'type' => $type,
            'quantity' => $this->format($delta),
            'balance_before' => $this->format($current),
            'balance_after' => $this->format($balance),
            'reason' => $reason,
            'unit_cost_milli_cents' => $averageCost,
            'cost_total_cents' => $movementCost,
        ]);
    }

    public function costForQuantity(int $quantityMilliUnits, ?int $averageCostMilliCents): ?int
    {
        if ($averageCostMilliCents === null) {
            return null;
        }

        [$cost, $remainder] = $this->multiplyDivide(
            $quantityMilliUnits,
            $averageCostMilliCents,
            1_000_000,
        );
        if ($remainder >= 500_000) {
            $cost++;
        }

        return $cost;
    }

    private function roundedDivision(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function multiplyDivide(int $left, int $right, int $divisor): array
    {
        $quotient = 0;
        $remainder = 0;
        $termQuotient = intdiv($left, $divisor);
        $termRemainder = $left % $divisor;

        while ($right > 0) {
            if ($right % 2 === 1) {
                if ($quotient > PHP_INT_MAX - $termQuotient) {
                    throw ValidationException::withMessages([
                        'total_cost' => 'O custo calculado excede o limite suportado.',
                    ]);
                }
                $quotient += $termQuotient;
                $remainder += $termRemainder;
                if ($remainder >= $divisor) {
                    $quotient++;
                    $remainder -= $divisor;
                }
            }

            $right = intdiv($right, 2);
            if ($right > 0) {
                if ($termQuotient > intdiv(PHP_INT_MAX, 2)) {
                    throw ValidationException::withMessages([
                        'total_cost' => 'O custo calculado excede o limite suportado.',
                    ]);
                }
                $termQuotient *= 2;
                $termRemainder *= 2;
                if ($termRemainder >= $divisor) {
                    $termQuotient++;
                    $termRemainder -= $divisor;
                }
            }
        }

        return [$quotient, $remainder];
    }
}

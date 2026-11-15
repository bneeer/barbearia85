<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CheckoutFinancials
{
    public function allocateDiscount(array $lines, int $discountCents): array
    {
        $subtotal = array_sum(array_column($lines, 'gross_cents'));
        if ($discountCents < 0 || $discountCents > $subtotal) {
            throw ValidationException::withMessages([
                'discount_cents' => 'O desconto não pode superar o subtotal.',
            ]);
        }

        if ($discountCents === 0 || $subtotal === 0) {
            return array_map(fn (array $line) => [
                ...$line,
                'discount_cents' => 0,
                'net_cents' => $line['gross_cents'],
            ], $lines);
        }

        $remainderOrder = [];
        $allocated = 0;
        foreach ($lines as $index => $line) {
            [$share, $remainder] = $this->multiplyDivide(
                $discountCents,
                $line['gross_cents'],
                $subtotal,
            );
            $lines[$index]['discount_cents'] = $share;
            $allocated += $share;
            $remainderOrder[] = [
                'index' => $index,
                'remainder' => $remainder,
                'key' => $line['key'],
            ];
        }

        usort($remainderOrder, fn (array $left, array $right) => ($right['remainder'] <=> $left['remainder']) ?: ($left['key'] <=> $right['key'])
        );

        for ($remaining = $discountCents - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $lineIndex = $remainderOrder[$index]['index'];
            $lines[$lineIndex]['discount_cents']++;
        }

        return array_map(fn (array $line) => [
            ...$line,
            'net_cents' => $line['gross_cents'] - $line['discount_cents'],
        ], $lines);
    }

    public function commissionCents(int $netServiceCents, string|int|float $percentage): int
    {
        $raw = str_replace(',', '.', (string) $percentage);
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');
        $basisPoints = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        $wholeCents = intdiv($netServiceCents, 10000) * $basisPoints;
        $remainderCents = ($netServiceCents % 10000) * $basisPoints;

        return $wholeCents + intdiv($remainderCents + 5000, 10000);
    }

    private function multiplyDivide(int $left, int $right, int $divisor): array
    {
        $quotient = 0;
        $remainder = 0;
        $termQuotient = intdiv($left, $divisor);
        $termRemainder = $left % $divisor;

        while ($right > 0) {
            if ($right % 2 === 1) {
                $quotient += $termQuotient;
                $remainder += $termRemainder;
                if ($remainder >= $divisor) {
                    $quotient++;
                    $remainder -= $divisor;
                }
            }

            $right = intdiv($right, 2);
            if ($right > 0) {
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

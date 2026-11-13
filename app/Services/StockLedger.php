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
    ): StockMovement {
        $current = $this->milliUnits((string) $item->stock_quantity);
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

        $item->stock_quantity = $item instanceof Product
            ? intdiv($balance, 1000)
            : $this->format($balance);
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
        ]);
    }
}

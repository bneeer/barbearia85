<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Services\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InventoryController extends Controller
{
    private function cents(string|int|float $amount): int
    {
        $amount = str_replace(',', '.', (string) $amount);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function authorizeAdmin(Request $request): int
    {
        abort_unless($request->user()->isAdmin() && $request->user()->barbershop_id, 403);

        return (int) $request->user()->barbershop_id;
    }

    private function itemsQuery(int $barbershopId)
    {
        $products = Product::query()->where('barbershop_id', $barbershopId)
            ->selectRaw("id, name, brand, description, price, stock_quantity, min_stock, average_cost_milli_cents, NULL as unit, 'product' as type");
        $supplies = Supply::query()->where('barbershop_id', $barbershopId)
            ->selectRaw("id, name, COALESCE(brand, '') as brand, description, NULL as price, stock_quantity, min_stock, average_cost_milli_cents, unit, 'supply' as type");

        return DB::query()->fromSub($products->unionAll($supplies), 'inventory_items');
    }

    public function index(Request $request)
    {
        $barbershopId = $this->authorizeAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['product', 'supply'])],
            'status' => ['nullable', Rule::in(['all', 'low', 'out', 'available'])],
        ]);
        $query = $this->itemsQuery($barbershopId)
            ->when(($filters['search'] ?? '') !== '', fn ($query) => $query->where(function ($query) use ($filters) {
                $query->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('brand', 'like', '%'.$filters['search'].'%');
            }))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(($filters['status'] ?? 'all') === 'low', fn ($query) => $query->whereColumn('stock_quantity', '<=', 'min_stock'))
            ->when(($filters['status'] ?? 'all') === 'out', fn ($query) => $query->where('stock_quantity', 0))
            ->when(($filters['status'] ?? 'all') === 'available', fn ($query) => $query->where('stock_quantity', '>', 0))
            ->orderBy('name')->orderBy('type')->orderBy('id');
        $products = Product::query()->where('barbershop_id', $barbershopId);
        $supplies = Supply::query()->where('barbershop_id', $barbershopId);

        return Inertia::render('Inventory/Index', [
            'items' => $query->paginate(15)->withQueryString(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? 'all',
            ],
            'counts' => [
                'products' => (clone $products)->count(),
                'supplies' => (clone $supplies)->count(),
                'low_stock' => $this->itemsQuery($barbershopId)
                    ->whereColumn('stock_quantity', '<=', 'min_stock')->count(),
            ],
            'movements' => StockMovement::query()->where('barbershop_id', $barbershopId)
                ->with('user')
                ->latest('id')->paginate(10, ['*'], 'movements_page')
                ->withQueryString()
                ->through(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type,
                    'cost_total_cents' => $movement->cost_total_cents,
                    'quantity' => $movement->quantity,
                    'balance_after' => $movement->balance_after,
                    'reason' => $movement->reason,
                    'item_name' => $movement->stockable_name,
                    'user_name' => $movement->user->name,
                    'created_at' => $movement->created_at->toIso8601String(),
                    'unit' => $movement->unit,
                ]),
        ]);
    }

    public function storeSupply(Request $request, StockLedger $ledger)
    {
        $barbershopId = $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', Rule::in(['un', 'ml', 'g', 'L', 'kg'])],
            'min_stock' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);
        if ($data['unit'] === 'un' && $ledger->milliUnits($data['min_stock']) % 1000 !== 0) {
            throw ValidationException::withMessages([
                'min_stock' => 'A unidade deve usar uma quantidade inteira.',
            ]);
        }

        Supply::create([
            ...$data,
            'barbershop_id' => $barbershopId,
            'stock_quantity' => 0,
        ]);

        return back()->with('success', 'Insumo cadastrado.');
    }

    public function updateSupply(Request $request, int $id, StockLedger $ledger)
    {
        $barbershopId = $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', Rule::in(['un', 'ml', 'g', 'L', 'kg'])],
            'min_stock' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);
        if ($data['unit'] === 'un' && $ledger->milliUnits($data['min_stock']) % 1000 !== 0) {
            throw ValidationException::withMessages([
                'min_stock' => 'A unidade deve usar uma quantidade inteira.',
            ]);
        }
        DB::transaction(function () use ($barbershopId, $id, $data, $ledger) {
            $supply = Supply::query()->where('barbershop_id', $barbershopId)
                ->lockForUpdate()->findOrFail($id);
            if ($supply->unit !== $data['unit']
                && ($ledger->milliUnits((string) $supply->stock_quantity) > 0
                    || $supply->stockMovements()->exists())) {
                throw ValidationException::withMessages([
                    'unit' => 'A unidade não pode ser alterada após registrar movimentações.',
                ]);
            }
            $supply->update($data);
        });

        return back()->with('success', 'Insumo atualizado.');
    }

    public function storeMovement(Request $request, StockLedger $ledger)
    {
        $barbershopId = $this->authorizeAdmin($request);
        $data = $request->validate([
            'item_type' => ['required', Rule::in(['product', 'supply'])],
            'item_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['entry', 'exit', 'adjustment'])],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason' => ['required', 'string', 'max:255'],
            'total_cost' => ['required_if:type,entry', 'nullable', 'regex:/^\d{1,9}([,.]\d{1,2})?$/'],
        ]);

        DB::transaction(function () use ($barbershopId, $data, $ledger, $request) {
            $item = $data['item_type'] === 'product'
                ? Product::query()->where('barbershop_id', $barbershopId)->lockForUpdate()->findOrFail($data['item_id'])
                : Supply::query()->where('barbershop_id', $barbershopId)->lockForUpdate()->findOrFail($data['item_id']);
            $quantity = $ledger->milliUnits($data['quantity']);

            if (($item instanceof Product || $item->unit === 'un') && $quantity % 1000 !== 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'A quantidade deste item deve ser inteira.',
                ]);
            }
            if ($data['type'] !== 'adjustment' && $quantity === 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'A quantidade deve ser maior que zero.',
                ]);
            }

            $current = $ledger->milliUnits((string) $item->stock_quantity);
            $delta = match ($data['type']) {
                'entry' => $quantity,
                'exit' => -$quantity,
                'adjustment' => $quantity - $current,
            };

            $ledger->record(
                $item,
                $delta,
                $data['type'],
                $data['reason'],
                $request->user(),
                null,
                $data['type'] === 'entry' ? $this->cents($data['total_cost']) : null,
            );
        });

        return back()->with('success', 'Movimentação registrada.');
    }
}

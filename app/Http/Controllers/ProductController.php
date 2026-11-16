<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin() && $request->user()->barbershop_id, 403);
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        $this->authorizeAdmin($request);
        abort_unless($product->barbershop_id === $request->user()->barbershop_id, 404);
    }

    private function validatedData(Request $request, array $extraRules = []): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'min_stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:999999999'],
            ...$extraRules,
        ], [
            'price.regex' => 'O preço deve ter no máximo duas casas decimais.',
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return redirect()->route('inventory.index', [
            'type' => 'product',
            ...$filters,
            ...$request->validate(['page' => ['nullable', 'integer', 'min:1']]),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return redirect()->route('inventory.index', ['action' => 'create', 'item_type' => 'product']);
    }

    public function store(Request $request, StockLedger $ledger)
    {
        $this->authorizeAdmin($request);

        $data = $this->validatedData($request, $ledger->initialEntryRules());
        DB::transaction(function () use ($data, $request, $ledger) {
            $product = Product::create([
                ...Arr::except($data, ['initial_entry', 'initial_quantity', 'initial_total_cost']),
                'barbershop_id' => $request->user()->barbershop_id,
                'stock_quantity' => 0,
            ]);
            $ledger->recordInitialEntry($product, $data, $request->user());
        });

        return back(fallback: route('inventory.index'))->with('success', 'Produto cadastrado.');
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);

        return redirect()->route('inventory.index', [
            'action' => 'show', 'item_type' => 'product', 'item_id' => $product->id,
        ]);
    }

    public function edit(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);

        return redirect()->route('inventory.index', [
            'action' => 'edit', 'item_type' => 'product', 'item_id' => $product->id,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);
        $product->update($this->validatedData($request));

        return back(fallback: route('inventory.index'))->with('success', 'Produto atualizado.');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);
        DB::transaction(function () use ($product) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($locked->stock_quantity > 0) {
                throw ValidationException::withMessages([
                    'archive' => 'Zere o saldo por movimentação antes de arquivar o produto.',
                ]);
            }
            $locked->delete();
        });

        return back(fallback: route('inventory.index'))->with('success', 'Produto arquivado.');
    }
}

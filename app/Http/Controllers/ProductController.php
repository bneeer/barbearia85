<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

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

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'price.regex' => 'O preço deve ter no máximo duas casas decimais.',
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Products/Index', [
            'products' => Product::where('barbershop_id', $request->user()->barbershop_id)
                ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters) {
                    $query->where(function ($query) use ($filters) {
                        $query->where('name', 'like', '%'.$filters['search'].'%')
                            ->orWhere('brand', 'like', '%'.$filters['search'].'%');
                    });
                })
                ->orderBy('name')->orderBy('id')
                ->paginate(10)->withQueryString(),
            'filters' => ['search' => $filters['search'] ?? ''],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Products/Create');
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        Product::create([
            ...$this->validatedData($request),
            'barbershop_id' => $request->user()->barbershop_id,
        ]);

        return redirect()->route('products.index');
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);

        return Inertia::render('Products/Show', ['product' => $product]);
    }

    public function edit(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);

        return Inertia::render('Products/Edit', ['product' => $product]);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);
        $product->update($this->validatedData($request));

        return redirect()->route('products.index');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);
        $product->delete();

        return redirect()->route('products.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ServiceController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin() && $request->user()->barbershop_id, 403);
    }

    private function authorizeService(Request $request, Service $service): void
    {
        $this->authorizeAdmin($request);
        abort_unless($service->barbershop_id === $request->user()->barbershop_id, 404);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'duration' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'is_active' => ['required', 'boolean'],
        ], [
            'price.regex' => 'O preço padrão deve ter no máximo duas casas decimais.',
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Services/Index', [
            'services' => Service::where('barbershop_id', $request->user()->barbershop_id)
                ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters) {
                    $query->where('name', 'like', '%'.$filters['search'].'%');
                })
                ->orderBy('name')->orderBy('id')
                ->paginate(10)->withQueryString(),
            'filters' => ['search' => $filters['search'] ?? ''],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Services/Create');
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        Service::create([
            ...$this->validatedData($request),
            'barbershop_id' => $request->user()->barbershop_id,
        ]);

        return redirect()->route('services.index');
    }

    public function show(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);

        return Inertia::render('Services/Show', ['service' => $service]);
    }

    public function edit(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);

        return Inertia::render('Services/Edit', ['service' => $service]);
    }

    public function update(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);
        $service->update($this->validatedData($request));

        return redirect()->route('services.index');
    }

    public function destroy(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);
        $service->delete();

        return redirect()->route('services.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Supply;
use App\Services\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    private function validatedData(Request $request, StockLedger $ledger): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'duration' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'commission_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'is_active' => ['required', 'boolean'],
            'supply_items' => ['sometimes', 'array', 'max:50'],
            'supply_items.*.supply_id' => ['required', 'integer', 'distinct', Rule::exists('supplies', 'id')
                ->where('barbershop_id', $request->user()->barbershop_id)->whereNull('deleted_at')],
            'supply_items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'price.regex' => 'O preço padrão deve ter no máximo duas casas decimais.',
            'commission_percent.regex' => 'A comissão deve ter no máximo duas casas decimais.',
        ]);

        $supplies = Supply::query()->where('barbershop_id', $request->user()->barbershop_id)
            ->whereIn('id', collect($data['supply_items'] ?? [])->pluck('supply_id'))->get()->keyBy('id');
        foreach ($data['supply_items'] ?? [] as $index => $item) {
            $supply = $supplies->get($item['supply_id']);
            if ($supply && $supply->unit === 'un' && $ledger->milliUnits($item['quantity']) % 1000 !== 0) {
                throw ValidationException::withMessages([
                    "supply_items.{$index}.quantity" => 'A quantidade deste insumo deve ser inteira.',
                ]);
            }
        }

        return $data;
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

        return Inertia::render('Services/Create', [
            'supplies' => Supply::query()->where('barbershop_id', $request->user()->barbershop_id)
                ->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    public function store(Request $request, StockLedger $ledger)
    {
        $this->authorizeAdmin($request);
        $data = $this->validatedData($request, $ledger);

        DB::transaction(function () use ($data, $request) {
            $service = Service::create([
                ...collect($data)->except('supply_items')->all(),
                'barbershop_id' => $request->user()->barbershop_id,
            ]);
            if (array_key_exists('supply_items', $data)) {
                $service->supplies()->sync(collect($data['supply_items'])->mapWithKeys(fn ($item) => [
                    $item['supply_id'] => ['quantity' => $item['quantity']],
                ])->all());
            }
        });

        return redirect()->route('services.index');
    }

    public function show(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);

        return Inertia::render('Services/Show', ['service' => $service->load('supplies')]);
    }

    public function edit(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);

        return Inertia::render('Services/Edit', [
            'service' => $service->load('supplies'),
            'supplies' => Supply::query()->where('barbershop_id', $request->user()->barbershop_id)
                ->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    public function update(Request $request, Service $service, StockLedger $ledger)
    {
        $this->authorizeService($request, $service);
        $data = $this->validatedData($request, $ledger);
        DB::transaction(function () use ($service, $data) {
            $service->update(collect($data)->except('supply_items')->all());
            if (array_key_exists('supply_items', $data)) {
                $service->supplies()->sync(collect($data['supply_items'])->mapWithKeys(fn ($item) => [
                    $item['supply_id'] => ['quantity' => $item['quantity']],
                ])->all());
            }
        });

        return redirect()->route('services.index');
    }

    public function destroy(Request $request, Service $service)
    {
        $this->authorizeService($request, $service);
        $service->delete();

        return redirect()->route('services.index');
    }
}

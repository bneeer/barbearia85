<?php

namespace App\Http\Controllers;

use App\Models\BarberSalaryRate;
use App\Models\Barbershop;
use App\Models\Service;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Support\BarberPhoto;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class BarberController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin() && $request->user()->barbershop_id, 403);
    }

    private function authorizeBarber(Request $request, User $barber): void
    {
        $this->authorizeAdmin($request);
        abort_unless($barber->role === 'barber' && $barber->barbershop_id === $request->user()->barbershop_id, 404);
    }

    private function services(Request $request)
    {
        return Service::where('barbershop_id', $request->user()->barbershop_id)
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'is_active']);
    }

    private function barbershops()
    {
        return Barbershop::orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    private function validatedData(Request $request, ?User $barber = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($barber?->id)],
            'password' => [$barber ? 'sometimes' : 'required', 'string', 'min:6'],
            'phone' => ['nullable', 'string', 'max:64', new InternationalPhone],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['required', 'integer', 'distinct', Rule::exists('services', 'id')
                ->where('barbershop_id', $request->user()->barbershop_id)->whereNull('deleted_at')],
            'barbershop_ids' => ['sometimes', 'array'],
            'barbershop_ids.*' => ['required', 'integer', 'distinct', Rule::exists('barbershops', 'id')],
            'salary_base' => ['sometimes', 'array'],
            'salary_base.*' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);
        if (isset($data['salary_base'])) {
            $selectedIds = array_map('strval', $data['barbershop_ids'] ?? []);
            if (array_diff(array_map('strval', array_keys($data['salary_base'])), $selectedIds)) {
                throw ValidationException::withMessages([
                    'salary_base' => 'Informe salário apenas para unidades selecionadas.',
                ]);
            }
        }

        if (isset($data['phone'])) {
            $data['phone'] = PhoneNumber::normalize($data['phone']);
        }

        return $data;
    }

    private function syncBarbershops(User $barber, array $barbershopIds, array $salaryBase): void
    {
        $currentPivot = $barber->barbershops()->get()->keyBy('id');
        $previousIds = $currentPivot->keys()->map(fn ($id) => (int) $id)->all();
        $shopIds = array_unique([...array_map('intval', $barbershopIds), ...$previousIds]);
        $timezones = Barbershop::query()->whereIn('id', $shopIds)->pluck('timezone', 'id');
        $sync = [];

        foreach ($barbershopIds as $barbershopId) {
            $barbershopId = (int) $barbershopId;
            $today = CarbonImmutable::now($timezones[$barbershopId] ?? config('app.timezone'))->toDateString();
            $rawSalary = $salaryBase[$barbershopId] ?? $salaryBase[(string) $barbershopId] ?? null;
            $currentSalary = $currentPivot->get($barbershopId)?->pivot->salary_base_cents;
            $salaryCents = $rawSalary === null && $currentSalary !== null
                ? (int) $currentSalary
                : $this->cents((string) ($rawSalary ?? '0'));
            $sync[$barbershopId] = ['salary_base_cents' => $salaryCents];

            if ($currentSalary === null || (int) $currentSalary !== $salaryCents) {
                $previousDay = CarbonImmutable::parse($today)->subDay()->toDateString();
                $barber->salaryRates()->where('barbershop_id', $barbershopId)
                    ->whereNull('effective_until')->whereDate('effective_from', '<', $today)
                    ->update(['effective_until' => $previousDay]);
                $rate = BarberSalaryRate::query()->where('barbershop_id', $barbershopId)
                    ->where('user_id', $barber->id)->whereDate('effective_from', $today)->first();
                if ($rate) {
                    $rate->update(['salary_base_cents' => $salaryCents, 'effective_until' => null]);
                } else {
                    BarberSalaryRate::create([
                        'barbershop_id' => $barbershopId,
                        'user_id' => $barber->id,
                        'salary_base_cents' => $salaryCents,
                        'effective_from' => $today,
                    ]);
                }
            }
        }

        foreach (array_diff($previousIds, array_map('intval', $barbershopIds)) as $removedId) {
            $today = CarbonImmutable::now($timezones[$removedId] ?? config('app.timezone'))->toDateString();
            $previousDay = CarbonImmutable::parse($today)->subDay()->toDateString();
            $barber->salaryRates()->where('barbershop_id', $removedId)
                ->whereNull('effective_until')->whereDate('effective_from', '<', $today)
                ->update(['effective_until' => $previousDay]);
            $barber->salaryRates()->where('barbershop_id', $removedId)
                ->whereNull('effective_until')->whereDate('effective_from', $today)
                ->delete();
        }

        $barber->barbershops()->sync($sync);
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $amount), 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Barbers/Index', [
            'barbers' => User::where('role', 'barber')
                ->where('barbershop_id', $request->user()->barbershop_id)
                ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters) {
                    $search = $filters['search'];
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')->orderBy('id')
                ->select('id', 'name', 'email', 'phone', 'photo_path')
                ->with('barbershops:id,name')
                ->paginate(10)->withQueryString(),
            'filters' => ['search' => $filters['search'] ?? ''],
        ]);
    }

    public function show(Request $request, User $barber)
    {
        $this->authorizeBarber($request, $barber);

        return Inertia::render('Barbers/Show', [
            'barber' => $barber->load([
                'services' => fn ($query) => $query
                    ->where('services.barbershop_id', $request->user()->barbershop_id)
                    ->orderBy('name')->select('services.id', 'services.name', 'services.is_active'),
                'barbershops:id,name',
            ]),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Barbers/Create', [
            'services' => $this->services($request),
            'barbershops' => $this->barbershops(),
            'defaultBarbershopId' => $request->user()->barbershop_id,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $this->validatedData($request);
        $photo = $request->hasFile('photo') ? BarberPhoto::store($request->file('photo')) : null;

        try {
            DB::transaction(function () use ($request, $data, $photo) {
                $barber = new User(Arr::only($data, ['name', 'email', 'password', 'phone']));
                $barber->role = 'barber';
                $barber->barbershop_id = $request->user()->barbershop_id;
                $barber->photo_path = $photo;
                $barber->save();
                $barber->services()->sync($data['service_ids'] ?? []);
                $this->syncBarbershops(
                    $barber,
                    $data['barbershop_ids'] ?? [$request->user()->barbershop_id],
                    $data['salary_base'] ?? [],
                );
            });
        } catch (Throwable $exception) {
            if ($photo) {
                Storage::disk('public')->delete($photo);
            }
            throw $exception;
        }

        return redirect()->route('barbers.index');
    }

    public function edit(Request $request, User $barber)
    {
        $this->authorizeBarber($request, $barber);

        return Inertia::render('Barbers/Edit', [
            'barber' => $barber->load([
                'services' => fn ($query) => $query
                    ->where('services.barbershop_id', $request->user()->barbershop_id)
                    ->select('services.id', 'services.name', 'services.is_active'),
                'barbershops:id,name',
            ]),
            'services' => $this->services($request),
            'barbershops' => $this->barbershops(),
        ]);
    }

    public function update(Request $request, User $barber)
    {
        $this->authorizeBarber($request, $barber);
        $data = $this->validatedData($request, $barber);
        $oldPhoto = $barber->photo_path;
        $photo = $request->hasFile('photo') ? BarberPhoto::store($request->file('photo')) : null;

        try {
            DB::transaction(function () use ($barber, $data, $photo) {
                $barber->fill(Arr::only($data, ['name', 'email', 'phone', 'password']));
                if ($barber->isDirty('email')) {
                    $barber->email_verified_at = null;
                }
                if ($photo) {
                    $barber->photo_path = $photo;
                }
                $barber->save();
                $barber->services()->sync($data['service_ids'] ?? []);
                if (array_key_exists('barbershop_ids', $data)) {
                    $this->syncBarbershops($barber, $data['barbershop_ids'], $data['salary_base'] ?? []);
                }
            });
        } catch (Throwable $exception) {
            if ($photo) {
                Storage::disk('public')->delete($photo);
            }
            throw $exception;
        }

        if ($photo && $oldPhoto) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return redirect()->route('barbers.index');
    }

    public function destroy(Request $request, User $barber)
    {
        $this->authorizeBarber($request, $barber);
        $barber->delete();
        if ($barber->photo_path) {
            Storage::disk('public')->delete($barber->photo_path);
        }

        return redirect()->route('barbers.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Barbershop;
use App\Models\Service;
use App\Models\User;
use App\Support\BarberPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($barber?->id)],
            'password' => [$barber ? 'sometimes' : 'required', 'string', 'min:6'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['required', 'integer', 'distinct', Rule::exists('services', 'id')
                ->where('barbershop_id', $request->user()->barbershop_id)->whereNull('deleted_at')],
            'barbershop_ids' => ['sometimes', 'array'],
            'barbershop_ids.*' => ['required', 'integer', 'distinct', Rule::exists('barbershops', 'id')],
        ]);
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
                $barber->barbershops()->sync($data['barbershop_ids'] ?? [$request->user()->barbershop_id]);
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
                    $barber->barbershops()->sync($data['barbershop_ids']);
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

<?php

namespace App\Http\Controllers;

use App\Models\Barbershop;
use App\Models\BarbershopPhoto;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class BarbershopController extends Controller
{
    private const DAYS = [
        'monday' => 'Segunda-feira',
        'tuesday' => 'Terça-feira',
        'wednesday' => 'Quarta-feira',
        'thursday' => 'Quinta-feira',
        'friday' => 'Sexta-feira',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    private function barbers()
    {
        return User::where('role', 'barber')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'email', 'photo_path']);
    }

    private function defaultHours(): array
    {
        return array_fill_keys(array_keys(self::DAYS), [
            'open' => '09:00',
            'close' => '18:00',
            'closed' => true,
        ]);
    }

    private function validatedData(Request $request): array
    {
        $dayKeys = array_keys(self::DAYS);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'address' => ['required', 'string', 'max:255'],
            'zip_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'phones' => ['sometimes', 'array'],
            'phones.*' => ['required', 'string', 'max:64', 'distinct', new InternationalPhone],
            'is_open' => ['required', 'boolean'],
            'cancellation_notice_hours' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'no_show_tolerance_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'opening_hours' => ['required', 'array:'.implode(',', $dayKeys)],
            'barber_ids' => ['sometimes', 'array'],
            'barber_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('role', 'barber')],
        ];

        foreach ($dayKeys as $day) {
            $rules["opening_hours.$day"] = ['required', 'array:open,close,closed'];
            $rules["opening_hours.$day.open"] = ['required', 'date_format:H:i'];
            $rules["opening_hours.$day.close"] = [
                'required', 'date_format:H:i',
                Rule::when(! $request->boolean("opening_hours.$day.closed"), "after:opening_hours.$day.open"),
            ];
            $rules["opening_hours.$day.closed"] = ['required', 'boolean'];
        }

        $data = $request->validate($rules, [
            'zip_code.regex' => 'Informe um CEP válido no formato 00000-000.',
            'opening_hours.*.close.after' => 'O fechamento deve ser depois da abertura.',
        ]);

        $normalizedPhones = [];
        foreach ($data['phones'] ?? [] as $index => $phone) {
            $normalized = PhoneNumber::normalize($phone);
            if (in_array($normalized, $normalizedPhones, true)) {
                throw ValidationException::withMessages([
                    "phones.$index" => 'Este telefone já foi informado.',
                ]);
            }
            $normalizedPhones[] = $normalized;
        }
        if (array_key_exists('phones', $data)) {
            $data['phones'] = $normalizedPhones;
        }

        $data['zip_code'] = preg_replace('/\D/', '', $data['zip_code']);

        return $data;
    }

    private function pageData(?Barbershop $barbershop = null): array
    {
        return [
            'barbershop' => $barbershop,
            'barbers' => $this->barbers(),
            'barberIds' => $barbershop?->users()->pluck('users.id')->all() ?? [],
            'defaultHours' => $this->defaultHours(),
            'days' => self::DAYS,
        ];
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Barbershops/Index', [
            'barbershops' => Barbershop::query()
                ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters) {
                    $query->where(function ($query) use ($filters) {
                        $query->where('name', 'like', '%'.$filters['search'].'%')
                            ->orWhere('address', 'like', '%'.$filters['search'].'%');
                    });
                })
                ->withCount('users')->orderBy('name')->orderBy('id')
                ->paginate(10)->withQueryString(),
            'filters' => ['search' => $filters['search'] ?? ''],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Barbershops/Create', $this->pageData());
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $this->validatedData($request);
        $slug = Str::slug($data['name']) ?: 'barbearia';
        $baseSlug = $slug;
        $suffix = 2;
        while (Barbershop::where('slug', $slug)->exists()) {
            $slug = "$baseSlug-$suffix";
            $suffix++;
        }

        DB::transaction(function () use ($data, $slug) {
            $barbershop = Barbershop::create([
                ...Arr::except($data, ['barber_ids']),
                'slug' => $slug,
            ]);
            $barbershop->users()->sync($data['barber_ids'] ?? []);
        });

        return redirect()->route('barbershops.index');
    }

    public function show(Request $request, Barbershop $barbershop)
    {
        $this->authorizeAdmin($request);
        $barbershop->load(['users' => fn ($query) => $query
            ->where('role', 'barber')->orderBy('name')
            ->select('users.id', 'users.name', 'users.email', 'users.photo_path')]);
        $barbershop->load('photos');
        $barbershop->opening_hours ??= $this->defaultHours();

        return Inertia::render('Barbershops/Show', ['barbershop' => $barbershop]);
    }

    public function edit(Request $request, Barbershop $barbershop)
    {
        $this->authorizeAdmin($request);
        $barbershop->load('photos');

        return Inertia::render('Barbershops/Edit', $this->pageData($barbershop));
    }

    public function storePhotos(Request $request, Barbershop $barbershop)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($barbershop, $data, &$storedPaths) {
                foreach ($data['photos'] as $photo) {
                    $path = $photo->store("barbershop-photos/{$barbershop->id}", 'public');
                    if ($path === false) {
                        throw new \RuntimeException('Não foi possível salvar a foto da unidade.');
                    }

                    $storedPaths[] = $path;
                    $barbershop->photos()->create(['path' => $path]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }

        return redirect()->route('barbershops.edit', ['barbershop' => $barbershop, 'tab' => 'photos']);
    }

    public function destroyPhoto(Request $request, Barbershop $barbershop, BarbershopPhoto $photo)
    {
        $this->authorizeAdmin($request);
        abort_unless($photo->barbershop_id === $barbershop->id, 404);

        if (! Storage::disk('public')->delete($photo->path)) {
            throw new \RuntimeException('Não foi possível excluir a foto da unidade.');
        }

        $photo->delete();

        return redirect()->route('barbershops.edit', ['barbershop' => $barbershop, 'tab' => 'photos']);
    }

    public function showPhoto(BarbershopPhoto $photo)
    {
        abort_unless(Storage::disk('public')->exists($photo->path), 404);

        return Storage::disk('public')->response($photo->path);
    }

    public function publicGallery(Barbershop $barbershop)
    {
        return response()->json([
            'photos' => $barbershop->photos()->get(['id', 'barbershop_id', 'path'])
                ->map(fn (BarbershopPhoto $photo) => ['url' => $photo->url]),
        ]);
    }

    public function update(Request $request, Barbershop $barbershop)
    {
        $this->authorizeAdmin($request);
        $data = $this->validatedData($request);
        DB::transaction(function () use ($barbershop, $data) {
            $barbershop->update(Arr::except($data, ['barber_ids']));
            $barbershop->users()->sync($data['barber_ids'] ?? []);
        });

        return redirect()->route('barbershops.index');
    }

    public function destroy(Request $request, Barbershop $barbershop)
    {
        $this->authorizeAdmin($request);
        if (
            $barbershop->clients()->exists()
            || $barbershop->services()->exists()
            || $barbershop->appointments()->exists()
            || $barbershop->users()->exists()
            || User::where('barbershop_id', $barbershop->id)->exists()
        ) {
            return back()->withErrors([
                'barbershop' => 'Não é possível excluir uma unidade que ainda possui clientes, serviços, agendamentos ou usuários associados.',
            ]);
        }

        foreach ($barbershop->photos as $photo) {
            if (! Storage::disk('public')->delete($photo->path)) {
                throw new \RuntimeException('Não foi possível excluir as fotos da unidade.');
            }
        }

        $barbershop->delete();

        return redirect()->route('barbershops.index');
    }
}

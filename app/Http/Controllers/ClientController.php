<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Scopes\BarbershopScope;
use App\Services\ClientPrivacyLookup;
use App\Services\LoyaltyProgram;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    public function index(Request $request, ClientPrivacyLookup $lookup)
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($filters['search'] ?? '');

        return Inertia::render('Clients/Index', [
            'clients' => $lookup->clients()->with('barbershop:id,name')
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')))
                ->orderBy('name')->orderBy('id')->paginate(10)->withQueryString()
                ->through(fn (Client $client) => [
                    'id' => $client->id, 'name' => $client->name, 'email' => $client->email,
                    'phone' => $client->phone, 'unit' => $client->barbershop?->name,
                    'archived' => $client->trashed(),
                ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function show(Request $request, int $id, ClientPrivacyLookup $lookup, LoyaltyProgram $loyalty)
    {
        $this->authorizeAdmin($request);
        $client = $lookup->client($id);
        $appointments = Appointment::withoutGlobalScope(BarbershopScope::class)->withTrashed()
            ->where('client_id', $client->id)->with([
                'barbershop:id,name,timezone', 'barber:id,name', 'checkout',
                'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
                'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
            ])->orderByDesc('start_time')->orderByDesc('id')->paginate(10)->withQueryString()
            ->through(fn (Appointment $item) => [
                'id' => $item->id, 'status' => $item->status, 'archived' => $item->trashed(),
                'start' => $item->start_time->setTimezone($item->barbershop?->timezone ?? config('app.display_timezone'))->format('d/m/Y H:i'),
                'timezone' => $item->barbershop?->timezone, 'unit' => $item->barbershop?->name,
                'barber' => $item->barber?->name,
                'services' => $item->checkout
                    ? collect($item->checkout->services)->pluck('name')->all()
                    : ($item->services->isNotEmpty() ? $item->services : collect([$item->service])->filter())->pluck('name')->all(),
            ]);

        return Inertia::render('Clients/Show', [
            'client' => [
                'id' => $client->id, 'name' => $client->name, 'email' => $client->email,
                'phone' => $client->phone, 'notes' => $client->notes,
                'unit' => $client->barbershop?->name, 'archived' => $client->trashed(),
            ],
            'loyalty' => $loyalty->forClient($client),
            'appointments' => $appointments,
            'matches' => $lookup->matches($client),
            'requests' => \DB::table('privacy_requests')
                ->join('privacy_request_clients', 'privacy_request_clients.privacy_request_id', '=', 'privacy_requests.id')
                ->where('privacy_request_clients.client_id', $client->id)
                ->orderByDesc('privacy_requests.id')->get([
                    'privacy_requests.id', 'privacy_requests.protocol', 'privacy_requests.type', 'privacy_requests.status',
                ]),
        ]);
    }

    public function redeemLoyalty(Request $request, int $id, ClientPrivacyLookup $lookup, LoyaltyProgram $loyalty)
    {
        $this->authorizeAdmin($request);
        $client = $lookup->client($id);
        $loyalty->redeem($client, $request->user()->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Resgate da recompensa registrado.']);

        return redirect()->route('clients.show', ['id' => $client->id]);
    }
}

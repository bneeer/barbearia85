<?php

namespace App\Http\Controllers;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use App\Services\ClientPrivacyLookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PrivacyRequestController extends Controller
{
    private const TYPES = ['full_access', 'simple_access', 'correction', 'deletion', 'other'];

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    private function event(PrivacyRequest $privacyRequest, Request $request, string $event, array $details = []): void
    {
        PrivacyRequestEvent::create([
            'privacy_request_id' => $privacyRequest->id,
            'actor_id' => $request->user()->id,
            'event' => $event,
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return Inertia::render('PrivacyRequests/Index', [
            'requests' => PrivacyRequest::with('assignedTo:id,name')
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderBy('due_date')->orderByDesc('id')->paginate(15)
                ->through(fn (PrivacyRequest $item) => [
                    'id' => $item->id, 'protocol' => $item->protocol, 'type' => $item->type,
                    'status' => $item->status, 'due_date' => $item->due_date->format('Y-m-d'),
                    'assigned_to' => $item->assignedTo?->name,
                    'overdue' => $item->status === 'open' && $item->due_date->format('Y-m-d') < now(config('app.display_timezone'))->format('Y-m-d'),
                ]),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return Inertia::render('PrivacyRequests/Create', [
            'admins' => User::where('role', 'admin')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'received_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'channel' => ['required', Rule::in(['phone', 'email', 'in_person', 'other'])],
            'type' => ['required', Rule::in(self::TYPES)],
            'other_type' => ['nullable', 'required_if:type,other', 'string', 'max:120'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'due_reason' => ['nullable', 'string', 'max:500'],
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'admin')],
        ]);
        $received = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['received_at'], config('app.display_timezone'));
        $suggested = $data['type'] === 'full_access' ? $received->addDays(15)->format('Y-m-d') : null;
        if ($received->isFuture()) {
            throw ValidationException::withMessages(['received_at' => 'A data de recebimento não pode estar no futuro.']);
        }
        if ($data['due_date'] < $received->format('Y-m-d')) {
            throw ValidationException::withMessages(['due_date' => 'O prazo não pode ser anterior ao recebimento.']);
        }
        if ($suggested !== null && $suggested !== $data['due_date'] && ! trim($data['due_reason'] ?? '')) {
            throw ValidationException::withMessages(['due_reason' => 'Justifique o prazo diferente do sugerido.']);
        }

        $item = DB::transaction(function () use ($data, $request, $received, $suggested) {
            $item = PrivacyRequest::create([
                'protocol' => 'LGPD-'.Str::upper((string) Str::ulid()),
                'received_at' => $received->utc(), 'channel' => $data['channel'], 'type' => $data['type'],
                'other_type' => $data['type'] === 'other' ? $data['other_type'] : null,
                'suggested_due_date' => $suggested, 'due_date' => $data['due_date'],
                'due_reason' => $data['due_reason'] ?? null, 'assigned_to' => $data['assigned_to'],
            ]);
            $this->event($item, $request, 'created', ['due_date' => $data['due_date'], 'due_reason' => $data['due_reason'] ?? null, 'assigned_to' => (int) $data['assigned_to']]);
            if ($item->type === 'deletion') {
                $this->event($item, $request, 'deletion_blocked', ['reason' => 'retencao_indefinida']);
            }

            return $item;
        });

        return redirect()->route('privacy-requests.show', $item->id);
    }

    public function show(Request $request, int $id, ClientPrivacyLookup $lookup)
    {
        $this->authorizeAdmin($request);
        $item = PrivacyRequest::with(['assignedTo:id,name', 'events.actor:id,name'])->findOrFail($id);
        $clients = $item->clients()->withoutGlobalScopes()->withTrashed()->with('barbershop:id,name')->get();

        return Inertia::render('PrivacyRequests/Show', [
            'item' => [
                'id' => $item->id, 'protocol' => $item->protocol, 'type' => $item->type, 'other_type' => $item->other_type, 'channel' => $item->channel,
                'received_at' => $item->received_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                'due_date' => $item->due_date->format('Y-m-d'),
                'suggested_due_date' => $item->suggested_due_date?->format('Y-m-d'), 'due_reason' => $item->due_reason,
                'assigned_to' => $item->assigned_to, 'status' => $item->status,
                'identity_verified' => $item->identity_verified, 'identity_method' => $item->identity_method,
                'internal_search_done' => $item->internal_search_done, 'external_search_done' => $item->external_search_done,
                'shared_agents_done' => $item->shared_agents_done, 'response_sent' => $item->response_sent,
                'result' => $item->result, 'result_reason' => $item->result_reason,
                'retained_categories' => $item->retained_categories, 'completed_at' => $item->completed_at?->toIso8601String(),
            ],
            'admins' => User::where('role', 'admin')->orderBy('name')->get(['id', 'name']),
            'clients' => $clients->map(fn ($client) => [
                'id' => $client->id, 'name' => $client->name, 'unit' => $client->barbershop?->name,
                'archived' => $client->trashed(), 'matches' => $lookup->matches($client),
                'preview' => $item->type === 'deletion' ? $lookup->preview($client) : null,
            ]),
            'events' => $item->events->map(fn ($event) => [
                'event' => $event->event, 'actor' => $event->actor?->name, 'details' => $event->details,
                'created_at' => $event->created_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
            ]),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'admin')],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'due_reason' => ['nullable', 'string', 'max:500'],
            'identity_verified' => ['required', 'boolean'],
            'identity_method' => ['nullable', Rule::in(['existing_contact', 'in_person', 'other'])],
            'internal_search_done' => ['required', 'boolean'],
            'external_search_done' => ['required', 'boolean'],
            'shared_agents_done' => ['required', 'boolean'],
            'response_sent' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $item = PrivacyRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'open') {
                throw ValidationException::withMessages(['assigned_to' => 'Solicitação concluída não pode ser alterada.']);
            }
            if ($data['due_date'] < $item->received_at->setTimezone(config('app.display_timezone'))->format('Y-m-d')) {
                throw ValidationException::withMessages(['due_date' => 'O prazo não pode ser anterior ao recebimento.']);
            }
            if ($data['due_date'] !== $item->due_date->format('Y-m-d') && ! trim($data['due_reason'] ?? '')) {
                throw ValidationException::withMessages(['due_reason' => 'Justifique a alteração do prazo.']);
            }
            if ($data['identity_verified'] && empty($data['identity_method'])) {
                throw ValidationException::withMessages(['identity_method' => 'Informe o método de verificação.']);
            }
            if (! $data['identity_verified'] && $item->clients()->exists()) {
                throw ValidationException::withMessages(['identity_verified' => 'Há cadastros vinculados; não é possível retirar a verificação.']);
            }
            if (! $data['identity_verified']) {
                $data['identity_method'] = null;
            }
            $before = $item->only(array_keys($data));
            $item->fill($data)->save();
            $this->event($item, $request, 'updated', [
                'changed_fields' => array_keys($item->getChanges()),
                'previous_due_date' => $before['due_date']?->format('Y-m-d'),
                'due_date' => $data['due_date'], 'due_reason' => $data['due_reason'] ?? null,
                'assigned_to' => (int) $data['assigned_to'],
                'identity_verified' => (bool) $data['identity_verified'],
                'identity_method' => $data['identity_method'],
                'internal_search_done' => (bool) $data['internal_search_done'],
                'external_search_done' => (bool) $data['external_search_done'],
                'shared_agents_done' => (bool) $data['shared_agents_done'],
                'response_sent' => (bool) $data['response_sent'],
            ]);
        });

        return back();
    }

    public function linkClient(Request $request, int $id, ClientPrivacyLookup $lookup)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['client_id' => ['required', 'integer']]);
        $client = $lookup->client($data['client_id']);
        DB::transaction(function () use ($request, $id, $client) {
            $item = PrivacyRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'open' || ! $item->identity_verified) {
                throw ValidationException::withMessages(['client_id' => 'Verifique a identidade antes de vincular um cadastro a uma solicitação aberta.']);
            }
            if ($item->clients()->withoutGlobalScopes()->where('clients.id', $client->id)->exists()) {
                throw ValidationException::withMessages(['client_id' => 'Cadastro já vinculado.']);
            }
            $item->clients()->attach($client->id, ['linked_by' => $request->user()->id, 'created_at' => now()]);
            $item->update(['internal_search_done' => false, 'external_search_done' => false, 'shared_agents_done' => false, 'response_sent' => false]);
            $this->event($item, $request, 'client_linked', ['client_id' => $client->id]);
        });

        return back();
    }

    public function unlinkClient(Request $request, int $id, int $clientId)
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $id, $clientId) {
            $item = PrivacyRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'open') {
                throw ValidationException::withMessages(['client_id' => 'Solicitação concluída não pode ser alterada.']);
            }
            if (! $item->clients()->detach($clientId)) {
                throw ValidationException::withMessages(['client_id' => 'Cadastro não está vinculado.']);
            }
            $item->update(['internal_search_done' => false, 'external_search_done' => false, 'shared_agents_done' => false, 'response_sent' => false]);
            $this->event($item, $request, 'client_unlinked', ['client_id' => $clientId]);
        });

        return back();
    }

    public function complete(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'result' => ['required', Rule::in(['fulfilled', 'partial', 'denied', 'impossible'])],
            'result_reason' => ['nullable', 'string', 'max:500'],
            'retained_categories' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $item = PrivacyRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'open') {
                throw ValidationException::withMessages(['result' => 'Solicitação já concluída.']);
            }
            if (! $item->assignedTo || ! $item->assignedTo->isAdmin()) {
                throw ValidationException::withMessages(['result' => 'Atribua a solicitação a um administrador antes de concluir.']);
            }
            if (! $item->identity_verified || ! $item->internal_search_done || ! $item->external_search_done
                || ! $item->shared_agents_done || ! $item->response_sent) {
                throw ValidationException::withMessages(['result' => 'Confirme identidade, buscas, avaliação de compartilhamento e resposta antes de concluir.']);
            }
            if ($item->type === 'deletion' && $data['result'] === 'fulfilled') {
                throw ValidationException::withMessages(['result' => 'A exclusão integral está bloqueada enquanto a retenção não for definida.']);
            }
            if ($data['result'] !== 'fulfilled' && ! trim($data['result_reason'] ?? '')) {
                throw ValidationException::withMessages(['result_reason' => 'Informe o motivo do resultado.']);
            }
            if ($item->type === 'deletion' && ! trim($data['retained_categories'] ?? '')) {
                throw ValidationException::withMessages(['retained_categories' => 'Informe as categorias preservadas ou pendentes.']);
            }
            $item->update([
                ...$data, 'status' => 'closed', 'completed_at' => now(), 'completed_by' => $request->user()->id,
            ]);
            $this->event($item, $request, 'completed', $data);
        });

        return back();
    }

    public function reopen(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $id, $data) {
            $item = PrivacyRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'closed' || $item->result === 'fulfilled') {
                throw ValidationException::withMessages(['reason' => 'Somente solicitações com resultado não integral podem ser reabertas.']);
            }
            $this->event($item, $request, 'reopened', [
                'reason' => $data['reason'], 'previous_result' => $item->result,
                'previous_reason' => $item->result_reason, 'retained_categories' => $item->retained_categories,
            ]);
            $item->update([
                'status' => 'open', 'result' => null, 'result_reason' => null, 'retained_categories' => null,
                'completed_at' => null, 'completed_by' => null, 'response_sent' => false,
            ]);
        });

        return back();
    }
}

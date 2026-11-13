<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\Client;
use App\Models\PrivacyRequest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function setupClients(): array
    {
        $one = Barbershop::create(['name' => 'Centro', 'slug' => 'centro']);
        $two = Barbershop::create(['name' => 'Norte', 'slug' => 'norte']);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $one->id]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $one->id]);
        $first = Client::withoutGlobalScopes()->create([
            'barbershop_id' => $one->id, 'name' => 'Cliente A',
            'email' => ' A@Example.com ', 'phone' => '+5511999999999',
        ]);
        $match = Client::withoutGlobalScopes()->create([
            'barbershop_id' => $two->id, 'name' => 'Cliente B',
            'email' => 'a@example.com', 'phone' => '+5511888888888',
        ]);
        $other = Client::withoutGlobalScopes()->create([
            'barbershop_id' => $two->id, 'name' => 'Cliente A',
            'email' => 'other@example.com', 'phone' => '+5511777777777',
        ]);
        $match->delete();

        return [$admin, $barber, $first, $match, $other];
    }

    private function createData(User $admin, string $type = 'full_access'): array
    {
        return [
            'received_at' => '2026-11-01T10:00', 'channel' => 'phone',
            'type' => $type, 'due_date' => '2026-11-16',
            'assigned_to' => $admin->id,
        ];
    }

    private function progress(User $admin, array $extra = []): array
    {
        return [
            'assigned_to' => $admin->id, 'due_date' => '2026-11-16', 'due_reason' => '',
            'identity_verified' => true, 'identity_method' => 'existing_contact',
            'internal_search_done' => true, 'external_search_done' => true,
            'shared_agents_done' => true, 'response_sent' => true, ...$extra,
        ];
    }

    public function test_admin_lists_all_units_and_finds_archived_exact_matches(): void
    {
        [$admin, , $first, $match] = $this->setupClients();
        $this->actingAs($admin)->get('/clients')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Index')->where('clients.total', 3));
        $this->get("/clients/{$first->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Show')->has('matches', 1)->where('matches.0.id', $match->id)
            ->where('matches.0.archived', true)->where('matches.0.email_match', true));
        $this->get('/clients?search=other%40example.com')->assertInertia(fn (Assert $page) => $page
            ->where('clients.total', 1));
    }

    public function test_only_administrators_can_access_any_privacy_route(): void
    {
        [$admin, $barber, $first] = $this->setupClients();
        $this->travelTo(now(new \DateTimeZone('America/Sao_Paulo'))->setDate(2026, 11, 10));
        $this->actingAs($admin)->post('/privacy-requests', $this->createData($admin));
        $id = PrivacyRequest::firstOrFail()->id;
        $this->actingAs($barber);
        $this->get('/clients')->assertForbidden();
        $this->get("/clients/{$first->id}")->assertForbidden();
        $this->get('/privacy-requests')->assertForbidden();
        $this->get('/privacy-requests/create')->assertForbidden();
        $this->post('/privacy-requests', $this->createData($admin))->assertForbidden();
        $this->get("/privacy-requests/$id")->assertForbidden();
        $this->patch("/privacy-requests/$id", $this->progress($admin))->assertForbidden();
        $this->post("/privacy-requests/$id/clients", ['client_id' => $first->id])->assertForbidden();
        $this->delete("/privacy-requests/$id/clients/{$first->id}")->assertForbidden();
        $this->post("/privacy-requests/$id/complete", ['result' => 'fulfilled'])->assertForbidden();
        $this->post("/privacy-requests/$id/reopen", ['reason' => 'Acompanhamento'])->assertForbidden();
        $this->delete("/clients/{$first->id}")->assertStatus(405);
        auth()->logout();
        $this->get('/clients')->assertRedirect(route('login'));
        $this->get('/privacy-requests')->assertRedirect(route('login'));
    }

    public function test_protocol_deadline_validation_identity_linking_and_audit(): void
    {
        [$admin, , $first, $match] = $this->setupClients();
        $this->travelTo(now(new \DateTimeZone('America/Sao_Paulo'))->setDate(2026, 11, 10));
        $this->actingAs($admin);
        $this->post('/privacy-requests', [...$this->createData($admin), 'due_date' => '2026-11-17'])
            ->assertSessionHasErrors('due_reason');
        $this->post('/privacy-requests', $this->createData($admin))->assertSessionHasNoErrors();
        $item = PrivacyRequest::firstOrFail();
        $this->assertSame('2026-11-16', $item->suggested_due_date->format('Y-m-d'));
        $this->post("/privacy-requests/{$item->id}/clients", ['client_id' => $first->id])
            ->assertSessionHasErrors('client_id');
        $this->patch("/privacy-requests/{$item->id}", $this->progress($admin, ['due_date' => '2026-11-17']))
            ->assertSessionHasErrors('due_reason');
        $this->patch("/privacy-requests/{$item->id}", $this->progress($admin))->assertSessionHasNoErrors();
        $this->post("/privacy-requests/{$item->id}/clients", ['client_id' => $first->id])->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->response_sent);
        $this->post("/privacy-requests/{$item->id}/clients", ['client_id' => $match->id])->assertSessionHasNoErrors();
        $this->post("/privacy-requests/{$item->id}/clients", ['client_id' => $match->id])->assertSessionHasErrors('client_id');
        $this->get("/privacy-requests/{$item->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PrivacyRequests/Show')->has('clients', 2)->has('events', 4));
        $this->assertSame(4, DB::table('privacy_request_events')->where('privacy_request_id', $item->id)->count());
    }

    public function test_deletion_is_preview_only_and_cannot_be_marked_fulfilled(): void
    {
        [$admin, , $first] = $this->setupClients();
        $this->travelTo(now(new \DateTimeZone('America/Sao_Paulo'))->setDate(2026, 11, 10));
        $this->actingAs($admin)->post('/privacy-requests', $this->createData($admin, 'deletion'));
        $item = PrivacyRequest::firstOrFail();
        $this->post("/privacy-requests/{$item->id}/complete", ['result' => 'fulfilled'])
            ->assertSessionHasErrors('result');
        $this->patch("/privacy-requests/{$item->id}", $this->progress($admin))->assertSessionHasNoErrors();
        $this->post("/privacy-requests/{$item->id}/clients", ['client_id' => $first->id])->assertSessionHasNoErrors();
        $this->patch("/privacy-requests/{$item->id}", $this->progress($admin))->assertSessionHasNoErrors();
        $this->get("/privacy-requests/{$item->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PrivacyRequests/Show')->where('clients.0.preview.appointments', 0));
        $this->post("/privacy-requests/{$item->id}/complete", ['result' => 'fulfilled'])->assertSessionHasErrors('result');
        $this->post("/privacy-requests/{$item->id}/complete", ['result' => 'partial', 'result_reason' => 'Retenção pendente'])
            ->assertSessionHasErrors('retained_categories');
        $this->post("/privacy-requests/{$item->id}/complete", [
            'result' => 'partial', 'result_reason' => 'Retenção pendente', 'retained_categories' => 'Cadastro e histórico',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', ['id' => $first->id, 'name' => 'Cliente A']);
        $this->assertSame('closed', $item->fresh()->status);
        $this->post("/privacy-requests/{$item->id}/reopen", ['reason' => 'Acompanhar pendência'])
            ->assertSessionHasNoErrors();
        $this->assertSame('open', $item->fresh()->status);
        $this->assertFalse($item->fresh()->response_sent);
        $this->assertDatabaseHas('privacy_request_events', ['privacy_request_id' => $item->id, 'event' => 'reopened']);
    }

    public function test_history_and_preview_include_archived_and_future_appointments_across_units(): void
    {
        [$admin, $barber, $first] = $this->setupClients();
        $this->travelTo(now(new \DateTimeZone('America/Sao_Paulo'))->setDate(2026, 11, 10));
        $service = Service::withoutGlobalScopes()->create([
            'barbershop_id' => $first->barbershop_id, 'name' => 'Corte', 'description' => 'Corte',
            'duration' => 40, 'price' => 50, 'is_active' => true,
        ]);
        $data = [
            'barbershop_id' => $first->barbershop_id, 'client_id' => $first->id,
            'user_id' => $barber->id, 'service_id' => $service->id,
            'start_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDay(), 'end_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDay()->addMinutes(40),
            'status' => 'scheduled', 'created_at' => now(new \DateTimeZone('America/Sao_Paulo')), 'updated_at' => now(new \DateTimeZone('America/Sao_Paulo')),
        ];
        DB::table('appointments')->insert($data);
        DB::table('appointments')->insert([...$data, 'status' => 'cancelled', 'deleted_at' => now(new \DateTimeZone('America/Sao_Paulo'))]);
        $this->actingAs($admin)->get("/clients/{$first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 2));
        $this->post('/privacy-requests', $this->createData($admin, 'deletion'));
        $item = PrivacyRequest::firstOrFail();
        $this->patch("/privacy-requests/{$item->id}", $this->progress($admin))->assertSessionHasNoErrors();
        $this->post("/privacy-requests/{$item->id}/clients", ['client_id' => $first->id])->assertSessionHasNoErrors();
        $this->get("/privacy-requests/{$item->id}")->assertInertia(fn (Assert $page) => $page
            ->where('clients.0.preview.appointments', 2)->where('clients.0.preview.future_scheduled', 1));
        $this->delete("/privacy-requests/{$item->id}/clients/{$first->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 2);
        $this->assertDatabaseCount('clients', 3);
    }

    public function test_other_types_require_description_and_deadlines_do_not_reset(): void
    {
        [$admin] = $this->setupClients();
        $this->travelTo(now(new \DateTimeZone('America/Sao_Paulo'))->setDate(2026, 11, 20));
        $this->actingAs($admin);
        $this->post('/privacy-requests', $this->createData($admin, 'other'))->assertSessionHasErrors('other_type');
        $this->post('/privacy-requests', [
            ...$this->createData($admin, 'other'), 'other_type' => 'Portabilidade',
        ])->assertSessionHasNoErrors();
        $item = PrivacyRequest::firstOrFail();
        $this->assertNull($item->suggested_due_date);
        $this->get('/privacy-requests')->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.overdue', true));
        $this->patch("/privacy-requests/{$item->id}", $this->progress($admin, [
            'due_date' => '2026-11-25', 'due_reason' => 'Revisão do prazo aplicável',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('2026-11-01', $item->fresh()->received_at->setTimezone(config('app.display_timezone'))->format('Y-m-d'));
        $this->assertDatabaseHas('privacy_request_events', ['privacy_request_id' => $item->id, 'event' => 'updated']);
    }
}

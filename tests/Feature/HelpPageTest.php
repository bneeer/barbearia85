<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpPageTest extends TestCase
{
    public function test_admins_can_access_all_help_topics(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'admin']))
            ->get(route('help.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Index')
                ->has('documents', 10)
                ->where('documents.0.id', 'primeiros-passos')
                ->where('documents.9.id', 'notificacoes-e-privacidade'));
    }

    public function test_barbers_only_receive_help_for_available_features(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'barber']))
            ->get(route('help.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Index')
                ->has('documents', 5)
                ->where('documents.0.id', 'primeiros-passos')
                ->where('documents.4.id', 'minhas-comissoes')
                ->missing('documents.5'));
    }

    public function test_other_authenticated_roles_cannot_access_help(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'client']))
            ->get(route('help.index'))
            ->assertForbidden();
    }

    public function test_help_requires_authentication(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }
}

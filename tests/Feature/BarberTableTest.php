<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BarberTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_and_paginate_only_their_barbers(): void
    {
        $shop = Barbershop::create(['name' => 'Barbearia', 'slug' => 'barbearia']);
        $otherShop = Barbershop::create(['name' => 'Outra', 'slug' => 'outra']);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        User::factory()->count(11)->create(['role' => 'barber', 'barbershop_id' => $shop->id, 'name' => 'João']);
        User::factory()->create(['role' => 'barber', 'barbershop_id' => $otherShop->id, 'name' => 'João']);

        $this->actingAs($admin)->get('/barbers?search=Jo%C3%A3o')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Barbers/Index')
                ->where('barbers.total', 11)->has('barbers.data', 10)
                ->where('filters.search', 'João'));
        $this->actingAs($admin)->get('/barbers?search=Jo%C3%A3o&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('barbers.data', 1));
        $this->actingAs($admin)->get('/barbers?search=inexistente')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('barbers.total', 0));
    }
}

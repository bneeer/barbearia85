<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_is_public_and_shows_only_public_unit_contacts(): void
    {
        Barbershop::create([
            'name' => 'Norte', 'slug' => 'norte', 'is_open' => true,
            'phones' => ['+5511999999999'], 'address' => 'Rua privada para este teste',
        ]);
        Barbershop::create([
            'name' => 'Centro', 'slug' => 'centro', 'is_open' => true, 'phones' => [],
        ]);
        Barbershop::create([
            'name' => 'Fechada', 'slug' => 'fechada', 'is_open' => false,
            'phones' => ['+5511888888888'],
        ]);

        $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PrivacyPolicy')
            ->where('barbershops', [
                ['name' => 'Centro', 'phones' => []],
                ['name' => 'Norte', 'phones' => ['+5511999999999']],
            ]));
    }

    public function test_policy_is_available_without_any_units(): void
    {
        $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PrivacyPolicy')
            ->where('barbershops', []));
    }

    public function test_policy_is_available_without_a_unit_phone(): void
    {
        Barbershop::create(['name' => 'Centro', 'slug' => 'centro', 'is_open' => true]);

        $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PrivacyPolicy')
            ->where('barbershops', [['name' => 'Centro', 'phones' => []]]));
    }
}

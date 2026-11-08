<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_is_public_and_returns_photos_from_all_units(): void
    {
        $first = Barbershop::create(['name' => 'Centro', 'slug' => 'centro']);
        $second = Barbershop::create(['name' => 'Norte', 'slug' => 'norte', 'is_open' => false]);
        $photos = [
            $first->photos()->create(['path' => 'barbershop-photos/1/first.jpg']),
            $first->photos()->create(['path' => 'barbershop-photos/1/second.jpg']),
            $second->photos()->create(['path' => 'barbershop-photos/2/third.jpg']),
        ];

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('photos', 3)
            ->where('photos', array_map(fn ($photo) => ['url' => $photo->url], $photos)));
    }

    public function test_landing_page_works_without_unit_photos(): void
    {
        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')->has('photos', 0));
    }

    public function test_booking_stepper_is_public(): void
    {
        $this->get(route('booking'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Booking')->has('barbershops')->has('csrfToken'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizePhonesTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalization_is_dry_run_by_default_and_preserves_invalid_records_and_sent_snapshots(): void
    {
        $shop = Barbershop::create([
            'name' => 'Centro', 'slug' => 'centro',
            'phones' => ['(11) 3333-4444', '+55 11 3333-4444'],
        ]);
        $user = User::factory()->create(['phone' => '(11) 99999-9999']);
        $invalid = User::factory()->create(['phone' => '123']);
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Cliente',
            'email' => 'cliente@example.test', 'phone' => '+351 912 345 678',
        ]);
        $client->delete();
        $pending = Notification::create([
            'client_id' => $client->id, 'type' => 'whatsapp', 'template' => 'booking_confirmation',
            'status' => 'pending', 'payload' => ['recipient' => '(11) 99999-9999', 'phones' => ['(11) 3333-4444']],
        ]);
        $sent = Notification::create([
            'client_id' => $client->id, 'type' => 'whatsapp', 'template' => 'booking_confirmation',
            'status' => 'sent', 'payload' => ['recipient' => '(11) 99999-9999'],
        ]);
        $this->artisan('phones:normalize')->assertFailed();
        $this->assertSame('(11) 99999-9999', $user->fresh()->phone);
        $this->assertSame(['(11) 3333-4444', '+55 11 3333-4444'], $shop->fresh()->phones);
        $this->assertSame('(11) 99999-9999', $pending->fresh()->payload['recipient']);

        $this->artisan('phones:normalize', ['--write' => true])->assertFailed();
        $this->assertSame('+5511999999999', $user->fresh()->phone);
        $this->assertSame('123', $invalid->fresh()->phone);
        $this->assertSame('+351912345678', Client::withoutGlobalScopes()->findOrFail($client->id)->phone);
        $this->assertSame(['+551133334444'], $shop->fresh()->phones);
        $this->assertSame('+5511999999999', $pending->fresh()->payload['recipient']);
        $this->assertSame(['+551133334444'], $pending->fresh()->payload['phones']);
        $this->assertSame('(11) 99999-9999', $sent->fresh()->payload['recipient']);

        $invalid->update(['phone' => null]);
        $this->artisan('phones:normalize', ['--write' => true])
            ->expectsOutput('Registros normalizados: 0. Pendências manuais: 0.')->assertSuccessful();
    }
}

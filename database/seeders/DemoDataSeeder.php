<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Product;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Dados de demonstracao permitidos somente em local ou testing.');
        }

        $shops = Barbershop::all();
        $admin = User::where('role', 'admin')->first();

        if ($shops->isEmpty() || ! $admin) {
            throw new RuntimeException('Cadastre uma unidade e um administrador antes de popular os dados.');
        }

        DB::transaction(function () use ($shops, $admin) {
            foreach ($shops as $shop) {
                $this->populateShop($shop, $admin);
            }
        });

        $this->command?->info('Dados de demonstracao inseridos nas unidades existentes, sem apagar registros ou enviar notificacoes.');
    }

    private function populateShop(Barbershop $shop, User $admin): void
    {
        $faker = Factory::create('pt_BR');
        $faker->seed(85 + $shop->id);
        $services = collect([
            ['Corte social na tesoura', 40, 55, 'Corte classico com acabamento na tesoura.'],
            ['Degrade navalhado', 40, 60, 'Degrade com acabamento na navalha e finalizacao.'],
            ['Barba com toalha quente', 40, 45, 'Modelagem da barba com toalha quente e balm.'],
            ['Acabamento e pezinho', 20, 20, 'Contorno da nuca, costeletas e acabamento.'],
            ['Sobrancelha na navalha', 20, 15, 'Limpeza e alinhamento das sobrancelhas.'],
            ['Hidratacao capilar', 40, 40, 'Lavagem, hidratacao e finalizacao dos fios.'],
            ['Corte infantil', 40, 45, 'Corte para criancas com atendimento cuidadoso.'],
            ['Camuflagem de fios brancos', 40, 70, 'Tonalizacao suave para um resultado natural.'],
        ])->map(fn ($item) => Service::withoutGlobalScope(BarbershopScope::class)->firstOrCreate(
            ['barbershop_id' => $shop->id, 'name' => $item[0]],
            ['duration' => $item[1], 'price' => $item[2], 'description' => $item[3], 'is_active' => true],
        ))->filter(fn (Service $service) => $service->is_active)->values();

        if ($services->isEmpty()) {
            throw new RuntimeException("A unidade {$shop->id} precisa de servicos de demonstracao ativos.");
        }

        $products = collect([
            ['Pomada modeladora matte', 39.90, 'Fixacao media e acabamento sem brilho.'],
            ['Balm para barba', 34.90, 'Hidratacao e controle dos fios da barba.'],
            ['Shampoo de uso diario', 29.90, 'Limpeza suave para cabelos e couro cabeludo.'],
            ['Oleo para barba', 44.90, 'Maciez e hidratacao com fragrancia amadeirada.'],
        ])->map(fn ($item) => Product::withoutGlobalScope(BarbershopScope::class)->firstOrCreate(
            ['barbershop_id' => $shop->id, 'name' => $item[0], 'brand' => 'Linha 85 Demo'],
            ['price' => $item[1], 'description' => $item[2]],
        ));

        $clients = collect(range(1, 60))->map(fn ($index) => Client::withoutGlobalScope(BarbershopScope::class)->firstOrCreate(
            ['barbershop_id' => $shop->id, 'email' => "cliente.demo.{$shop->id}.{$index}@example.test"],
            [
                'name' => $faker->name(),
                'phone' => '1190000'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'notes' => $faker->randomElement([
                    'Dados ficticios. Prefere acabamento natural.',
                    'Dados ficticios. Cliente costuma agendar pelo WhatsApp.',
                    'Dados ficticios. Prefere atendimento no fim da tarde.',
                    'Dados ficticios para demonstracao.',
                ]),
            ],
        ));

        foreach (range(1, 5) as $index) {
            $barber = User::firstOrCreate(
                ['email' => "barbeiro.demo.{$shop->id}.{$index}@example.test"],
                [
                    'name' => $faker->firstNameMale().' '.$faker->lastName(),
                    'password' => Hash::make(Str::random(40)), 'role' => 'barber', 'barbershop_id' => $shop->id,
                    'phone' => '1190001'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                ],
            );
            $barber->barbershops()->syncWithoutDetaching([$shop->id]);
            $barber->services()->syncWithoutDetaching($services->pluck('id')->all());

            foreach ($shop->opening_hours ?? [] as $weekday => $hours) {
                if (! $hours['closed']) {
                    $barber->workingHours()->firstOrCreate(
                        ['day_of_week' => CarbonImmutable::parse($weekday)->dayOfWeek],
                        ['start_time' => $hours['open'], 'end_time' => $hours['close']],
                    );
                }
            }

            $barber->load('workingHours');
            $this->appointments($shop, $barber, $index, $clients, $services, $products, $admin);
        }
    }

    private function appointments(
        Barbershop $shop,
        User $barber,
        int $barberIndex,
        Collection $clients,
        Collection $services,
        Collection $products,
        User $admin,
    ): void {
        $now = CarbonImmutable::now($shop->timezone);

        for ($offset = -59; $offset <= 21; $offset++) {
            $day = $now->startOfDay()->addDays($offset);
            $hours = $shop->opening_hours[strtolower($day->format('l'))] ?? null;
            if (! $shop->is_open || ! $hours || $hours['closed']) {
                continue;
            }

            $open = $day->setTimeFromTimeString($hours['open']);
            $close = $day->setTimeFromTimeString($hours['close']);
            $seed = (int) $day->format('Ymd') + $barberIndex;

            for ($slot = 0; $slot < 3 + $seed % 4; $slot++) {
                $sequence = $seed + $slot;
                $selected = collect([$services[$sequence % $services->count()]]);
                if ($sequence % 3 === 0 && $services->count() > 1) {
                    $selected->push($services[($sequence + 1) % $services->count()]);
                }
                $start = $open->addMinutes($slot * 80);
                $end = $start->addMinutes((int) $selected->sum('duration'));
                $marker = "[demo:{$shop->id}:{$barber->id}:{$day->toDateString()}:{$slot}]";

                if ($end->gt($close) || ! $barber->workingHours->contains(
                    fn ($work) => (int) $work->day_of_week === $day->dayOfWeek
                        && $start->gte($day->setTimeFromTimeString($work->start_time))
                        && $end->lte($day->setTimeFromTimeString($work->end_time)),
                )) {
                    continue;
                }

                if (Appointment::withoutGlobalScope(BarbershopScope::class)->withTrashed()->where('notes', $marker)->exists()
                    || Appointment::withoutGlobalScope(BarbershopScope::class)->where('user_id', $barber->id)
                        ->where('status', '!=', 'cancelled')
                        ->where('start_time', '<', $end->utc())->where('end_time', '>', $start->utc())->exists()
                    || BlockedTime::where('user_id', $barber->id)
                        ->where('start_datetime', '<', $end->utc())->where('end_datetime', '>', $start->utc())->exists()) {
                    continue;
                }

                $status = $end->lte($now)
                    ? match ($sequence % 20) {
                        0 => 'cancelled',
                        1 => 'no_show',
                        2 => 'scheduled',
                        default => 'completed',
                    }
                : 'scheduled';
                $appointment = Appointment::withoutGlobalScope(BarbershopScope::class)->create([
                    'barbershop_id' => $shop->id,
                    'user_id' => $barber->id,
                    'client_id' => $clients[(($offset + 59) * 30 + ($barberIndex - 1) * 6 + $slot) % $clients->count()]->id,
                    'service_id' => $selected->first()->id,
                    'start_time' => $start->utc(), 'end_time' => $end->utc(),
                    'status' => $status, 'notes' => $marker,
                ]);
                $createdAt = $start->subDays(1 + $sequence % 7)->min($now)->utc();
                $appointment->forceFill([
                    'created_at' => $createdAt,
                    'updated_at' => $end->lte($now) ? $end->utc() : $createdAt,
                ])->saveQuietly();
                $appointment->services()->attach($selected->pluck('id')->all());

                if ($status === 'completed') {
                    $this->checkout($appointment, $selected, $products, $admin, $sequence);
                }
            }
        }
    }

    private function checkout(Appointment $appointment, Collection $services, Collection $products, User $admin, int $sequence): void
    {
        $serviceItems = $services->map(fn (Service $service) => [
            'id' => $service->id, 'name' => $service->name, 'duration' => $service->duration,
            'price_cents' => (int) round((float) $service->price * 100),
        ]);
        $product = $products[$sequence % $products->count()];
        $productItems = $sequence % 3 === 0 ? [[
            'id' => $product->id, 'name' => $product->name, 'brand' => $product->brand,
            'quantity' => $sequence % 2 + 1, 'price_cents' => (int) round((float) $product->price * 100),
        ]] : [];
        $servicesTotal = $serviceItems->sum('price_cents');
        $productsTotal = collect($productItems)->sum(fn ($item) => $item['quantity'] * $item['price_cents']);
        $discount = $sequence % 5 === 0 ? (int) round(($servicesTotal + $productsTotal) * 0.1) : 0;

        $checkout = $appointment->checkout()->create([
            'created_by' => $admin->id,
            'services' => $serviceItems->all(), 'products' => $productItems,
            'services_total_cents' => $servicesTotal, 'products_total_cents' => $productsTotal,
            'discount_cents' => $discount, 'total_cents' => $servicesTotal + $productsTotal - $discount,
            'payment_method' => ['pix', 'cash', 'debit_card', 'credit_card'][$sequence % 4],
            'notes' => $discount > 0 ? 'Demonstracao: desconto de fidelidade de 10%.' : null,
        ]);
        $checkout->forceFill([
            'created_at' => $appointment->end_time, 'updated_at' => $appointment->end_time,
        ])->saveQuietly();
    }
}

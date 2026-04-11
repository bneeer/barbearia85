<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Barbershop;

class BarbershopSeeder extends Seeder
{
    public function run(): void
    {
        Barbershop::firstOrCreate(
            ['slug' => 'barbearia-85'],
            [
                'name' => 'Barbearia 85',
                'timezone' => 'America/Sao_Paulo',
            ]);
    }
}

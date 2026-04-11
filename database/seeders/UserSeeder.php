<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Barbershop;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $barbershop = Barbershop::first();

        // ADMIN
        User::create([
            'name' => 'Admin',
            'email' => 'admin@teste.com',
            'password' => Hash::make('123456!'),
            'role' => 'admin',
            'barbershop_id' => $barbershop->id,
        ]);

        // BARBER
        User::create([
            'name' => 'Barbeiro Teste',
            'email' => 'barber@teste.com',
            'password' => Hash::make('123456?'),
            'role' => 'barber',
            'barbershop_id' => $barbershop->id,
        ]);
    }
}

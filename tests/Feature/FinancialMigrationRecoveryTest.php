<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FinancialMigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resumes_the_partial_mysql_migration_without_losing_existing_data(): void
    {
        $shop = Barbershop::create(['name' => 'Centro', 'slug' => 'centro']);
        $user = User::factory()->create(['barbershop_id' => $shop->id]);
        $user->barbershops()->attach($shop->id, ['salary_base_cents' => 120000]);

        Schema::drop('expense_payments');
        Schema::drop('expenses');
        Schema::drop('expense_recurrences');
        Schema::table('barber_salary_rates', function (Blueprint $table) {
            $table->dropIndex('barber_salary_rates_period_index');
        });

        $migration = require database_path('migrations/2026_11_16_000001_create_financial_management_tables.php');
        $migration->up();

        $this->assertSame(120000, (int) DB::table('barbershop_user')->value('salary_base_cents'));
        $this->assertTrue(Schema::hasIndex('barber_salary_rates', 'barber_salary_rates_period_index'));
        $this->assertTrue(Schema::hasTable('expense_recurrences'));
        $this->assertTrue(Schema::hasTable('expenses'));
        $this->assertTrue(Schema::hasTable('expense_payments'));

        $migration->up();
        $this->assertSame(120000, (int) DB::table('barbershop_user')->value('salary_base_cents'));
    }

    public function test_it_rejects_an_incompatible_existing_table(): void
    {
        Schema::drop('service_supplies');
        Schema::create('service_supplies', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id');
        });

        $migration = require database_path('migrations/2026_11_16_000001_create_financial_management_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A tabela existente service_supplies está incompleta');
        $migration->up();
    }
}

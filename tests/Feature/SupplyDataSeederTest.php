<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\Scopes\BarbershopScope;
use App\Models\Supply;
use Database\Seeders\SupplyDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SupplyDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_catalog_in_every_existing_shop_without_inventing_stock_or_costs(): void
    {
        $shop = Barbershop::create(['name' => 'Principal', 'slug' => 'principal']);
        $other = Barbershop::create(['name' => 'Outra', 'slug' => 'outra']);
        $this->app->instance('currentBarbershopId', $shop->id);

        $this->seed(SupplyDataSeeder::class);

        $this->assertDatabaseCount('barbershops', 2);
        $this->assertDatabaseCount('supplies', 84);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('products', 0);

        foreach ([$shop, $other] as $unit) {
            $supplies = Supply::withoutGlobalScope(BarbershopScope::class)
                ->where('barbershop_id', $unit->id)->get();
            $this->assertCount(42, $supplies);
            $this->assertCount(42, $supplies->pluck('name')->unique());
            foreach ($supplies as $supply) {
                $this->assertContains($supply->unit, ['un', 'ml', 'g', 'L', 'kg']);
                $this->assertSame('0.000', $supply->stock_quantity);
                $this->assertSame('0.000', $supply->min_stock);
                $this->assertNull($supply->average_cost_milli_cents);
                $this->assertNull($supply->brand);
                $this->assertNotEmpty($supply->description);
            }
            $this->assertStringContainsString(
                'coletor de perfurocortantes',
                $supplies->firstWhere('name', 'Lâminas de barbear')->description,
            );
            $this->assertSame('g', $supplies->firstWhere('name', 'Cera depilatória')->unit);
            $this->assertSame('ml', $supplies->firstWhere('name', 'Álcool 70%')->unit);
        }

        $before = Supply::withoutGlobalScope(BarbershopScope::class)->orderBy('id')->get()->toArray();
        $this->seed(SupplyDataSeeder::class);
        $this->assertSame($before, Supply::withoutGlobalScope(BarbershopScope::class)->orderBy('id')->get()->toArray());
    }

    public function test_it_preserves_existing_and_archived_supplies(): void
    {
        $shop = Barbershop::create(['name' => 'Principal', 'slug' => 'principal']);
        $existing = Supply::create([
            'barbershop_id' => $shop->id, 'name' => 'Shampoo neutro', 'brand' => 'Marca existente',
            'description' => 'Cadastro original', 'unit' => 'L', 'stock_quantity' => '3.500',
            'min_stock' => '1.500', 'average_cost_milli_cents' => 2500000,
        ]);
        $archived = Supply::create([
            'barbershop_id' => $shop->id, 'name' => 'Lâminas de barbear', 'unit' => 'un',
        ]);
        $archived->delete();
        $before = $existing->fresh()->getAttributes();
        $archivedBefore = $archived->fresh()->getAttributes();

        $this->seed(SupplyDataSeeder::class);
        $this->seed(SupplyDataSeeder::class);

        $this->assertDatabaseCount('supplies', 42);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame($archivedBefore, $archived->fresh()->getAttributes());
        $this->assertTrue($archived->fresh()->trashed());
    }

    public function test_it_requires_an_existing_shop(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cadastre uma unidade antes de popular os insumos.');

        try {
            $this->seed(SupplyDataSeeder::class);
        } finally {
            $this->assertDatabaseCount('barbershops', 0);
            $this->assertDatabaseCount('supplies', 0);
        }
    }
}

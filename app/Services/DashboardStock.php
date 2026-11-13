<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardStock
{
    public function summary(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $barbershopId,
        User $viewer,
    ): array {
        abort_unless($viewer->isAdmin(), 403);

        $products = DB::table('products')->whereNull('deleted_at')
            ->when($barbershopId, fn ($query) => $query->where('barbershop_id', $barbershopId));
        $supplies = DB::table('supplies')->whereNull('deleted_at')
            ->when($barbershopId, fn ($query) => $query->where('barbershop_id', $barbershopId));
        $start = $from->setTimezone(config('app.display_timezone'))->startOfDay()->utc();
        $end = $to->setTimezone(config('app.display_timezone'))->startOfDay()->addDay()->utc();
        $movements = DB::table('stock_movements')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->when($barbershopId, fn ($query) => $query->where('barbershop_id', $barbershopId));
        $sales = (clone $movements)
            ->where('stockable_type', Product::class)
            ->where('type', 'exit')
            ->whereNotNull('appointment_checkout_id');
        $salesCount = (clone $sales)->distinct()->count('appointment_checkout_id');
        $unitsSold = (int) (clone $sales)->sum(DB::raw('abs(quantity)'));
        $movementCounts = (clone $movements)->selectRaw(
            "sum(case when type = 'entry' then 1 else 0 end) as entries,
            sum(case when type = 'exit' and appointment_checkout_id is null then 1 else 0 end) as manual_exits,
            sum(case when type = 'adjustment' then 1 else 0 end) as adjustments"
        )->first();
        $topSellers = (clone $sales)
            ->selectRaw('stockable_id as id, stockable_name as name, sum(abs(quantity)) as quantity')
            ->groupBy('stockable_id', 'stockable_name')
            ->orderByDesc('quantity')->orderBy('stockable_name')->limit(5)->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
            ])->all();

        return [
            'current' => [
                'products_to_restock' => (clone $products)->where('min_stock', '>', 0)
                    ->whereColumn('stock_quantity', '<=', 'min_stock')->count(),
                'supplies_to_restock' => (clone $supplies)->where('min_stock', '>', 0)
                    ->whereColumn('stock_quantity', '<=', 'min_stock')->count(),
                'products_out_of_stock' => (clone $products)->where('stock_quantity', 0)->count(),
                'supplies_out_of_stock' => (clone $supplies)->where('stock_quantity', 0)->count(),
            ],
            'period' => [
                'units_sold' => $unitsSold,
                'sales_count' => $salesCount,
                'entries_count' => (int) ($movementCounts->entries ?? 0),
                'manual_exits_count' => (int) ($movementCounts->manual_exits ?? 0),
                'adjustments_count' => (int) ($movementCounts->adjustments ?? 0),
                'top_sellers' => $topSellers,
            ],
        ];
    }
}

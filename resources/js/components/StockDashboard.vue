<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { DashboardStockSummary } from '@/types/dashboard';

defineProps<{
    summary: DashboardStockSummary;
    rangeFrom: string;
    rangeTo: string;
}>();

const numberFormat = new Intl.NumberFormat('pt-BR');

function inventoryUrl(status?: string) {
    return status ? `/inventory?status=${status}` : '/inventory';
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="stock-dashboard-title">
        <div>
            <h2 id="stock-dashboard-title" class="text-xl font-semibold">
                Estoque
            </h2>
            <p class="text-sm text-muted-foreground">
                Saldos atuais e movimentações de {{ rangeFrom }} a
                {{ rangeTo }}. As quantidades vendidas consideram apenas
                produtos.
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardTitle>Itens para reposição</CardTitle>
                </CardHeader>
                <CardContent class="space-y-1">
                    <p class="text-3xl font-semibold">
                        {{
                            numberFormat.format(
                                summary.current.products_to_restock +
                                    summary.current.supplies_to_restock,
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ summary.current.products_to_restock }} produtos ·
                        {{ summary.current.supplies_to_restock }} insumos
                    </p>
                    <Link
                        :href="inventoryUrl('low')"
                        class="inline-block text-sm text-primary underline"
                        >Ver itens para reposição</Link
                    >
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Sem estoque</CardTitle></CardHeader>
                <CardContent class="space-y-1">
                    <p class="text-3xl font-semibold">
                        {{
                            numberFormat.format(
                                summary.current.products_out_of_stock +
                                    summary.current.supplies_out_of_stock,
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ summary.current.products_out_of_stock }} produtos ·
                        {{ summary.current.supplies_out_of_stock }} insumos
                    </p>
                    <Link
                        :href="inventoryUrl('out')"
                        class="inline-block text-sm text-primary underline"
                        >Ver itens zerados</Link
                    >
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Produtos vendidos</CardTitle>
                </CardHeader>
                <CardContent class="space-y-1">
                    <p class="text-3xl font-semibold">
                        {{ numberFormat.format(summary.period.units_sold) }}
                        unidades
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Em {{ numberFormat.format(summary.period.sales_count) }}
                        fechamentos no período
                    </p>
                    <Link
                        :href="inventoryUrl()"
                        class="inline-block text-sm text-primary underline"
                        >Abrir estoque</Link
                    >
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Movimentações no período</CardTitle>
                </CardHeader>
                <CardContent class="space-y-1 text-sm">
                    <p>
                        Entradas:
                        {{ numberFormat.format(summary.period.entries_count) }}
                    </p>
                    <p>
                        Saídas manuais:
                        {{
                            numberFormat.format(
                                summary.period.manual_exits_count,
                            )
                        }}
                    </p>
                    <p>
                        Ajustes:
                        {{
                            numberFormat.format(
                                summary.period.adjustments_count,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        Quantidades não são somadas entre unidades diferentes.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Mais vendidos no período</CardTitle>
            </CardHeader>
            <CardContent>
                <ol v-if="summary.period.top_sellers.length" class="divide-y">
                    <li
                        v-for="(product, index) in summary.period.top_sellers"
                        :key="product.id"
                        class="flex items-center justify-between gap-4 py-2"
                    >
                        <span class="min-w-0 truncate">
                            {{ index + 1 }}. {{ product.name }}
                        </span>
                        <span class="shrink-0 font-medium">
                            {{ numberFormat.format(product.quantity) }} un
                        </span>
                    </li>
                </ol>
                <p v-else class="text-sm text-muted-foreground">
                    Nenhum produto vendido neste período.
                </p>
            </CardContent>
        </Card>
    </section>
</template>

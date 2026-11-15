<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    barbershops: { id: number; name: string }[];
    selectedBarbershopId: number | null;
    filters: { from: string; to: string };
    summary: {
        commission_cents: number;
        checkout_count: number;
        incomplete_checkout_count: number;
    } | null;
}>();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const money = (cents: number) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(cents / 100);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Minhas comissões', href: '/finance' }] },
});

function filter(shopId = props.selectedBarbershopId) {
    router.get(
        '/finance',
        { barbershop_id: shopId ?? '', from: from.value, to: to.value },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Minhas comissões" />
    <div class="max-w-3xl space-y-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">Minhas comissões</h1>
            <p class="text-sm text-muted-foreground">
                Consulta individual; valores de despesas, salário-base e lucro
                da unidade não são exibidos.
            </p>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <select
                :value="selectedBarbershopId ?? ''"
                class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                aria-label="Unidade"
                @change="
                    filter(Number(($event.target as HTMLSelectElement).value))
                "
            >
                <option
                    v-for="shop in barbershops"
                    :key="shop.id"
                    :value="shop.id"
                >
                    {{ shop.name }}
                </option>
            </select>
            <label class="grid gap-1 text-sm"
                >De<Input v-model="from" type="date"
            /></label>
            <label class="grid gap-1 text-sm"
                >Até<Input v-model="to" type="date"
            /></label>
        </div>
        <button
            type="button"
            class="rounded-md border px-4 py-2 text-sm"
            @click="filter()"
        >
            Aplicar período
        </button>
        <Card v-if="summary">
            <CardHeader
                ><CardTitle>Comissões registradas</CardTitle></CardHeader
            >
            <CardContent class="space-y-2">
                <p class="text-2xl font-semibold">
                    {{ money(summary.commission_cents) }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ summary.checkout_count }} checkout(s) no período.
                </p>
                <p
                    v-if="summary.incomplete_checkout_count"
                    class="text-sm text-amber-700"
                    role="status"
                >
                    {{ summary.incomplete_checkout_count }} checkout(s)
                    antigo(s) não têm snapshot de comissão.
                </p>
            </CardContent>
        </Card>
        <p v-else class="rounded-md border p-4 text-sm text-muted-foreground">
            Nenhuma unidade associada.
        </p>
    </div>
</template>

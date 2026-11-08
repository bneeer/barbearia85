<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BarbershopForm from '@/components/BarbershopForm.vue';
import type {
    Barbershop,
    BarbershopBarber,
    OpeningHours,
} from '@/types/barbershop';

defineProps<{
    barbershop: Barbershop;
    barbers: BarbershopBarber[];
    barberIds: number[];
    defaultHours: OpeningHours;
    days: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Unidades', href: '/barbershops' },
            { title: 'Editar unidade', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Editar unidade" />
    <div class="max-w-3xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Editar unidade</h1>
        <BarbershopForm
            :barbershop="barbershop"
            :barbers="barbers"
            :barber-ids="barberIds"
            :default-hours="defaultHours"
            :days="days"
        />
    </div>
</template>

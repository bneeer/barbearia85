<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BarbershopForm from '@/components/BarbershopForm.vue';
import type { BarbershopBarber, OpeningHours } from '@/types/barbershop';

defineProps<{
    barbers: BarbershopBarber[];
    barberIds: number[];
    defaultHours: OpeningHours;
    days: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Unidades', href: '/barbershops' },
            { title: 'Nova unidade', href: '/barbershops/create' },
        ],
    },
});
</script>

<template>
    <Head title="Nova unidade" />
    <div class="max-w-3xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Nova unidade</h1>
        <BarbershopForm
            :barbers="barbers"
            :barber-ids="barberIds"
            :default-hours="defaultHours"
            :days="days"
        />
    </div>
</template>

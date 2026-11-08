<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BarberForm from '@/components/BarberForm.vue';
import type { Barber, BarberService } from '@/types/barber';

defineProps<{
    barber: Barber;
    services: BarberService[];
    barbershops: { id: number; name: string }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Barbeiros', href: '/barbers' },
            { title: 'Editar barbeiro', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Editar barbeiro" />
    <div class="max-w-xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Editar barbeiro</h1>
        <BarberForm
            :barber="barber"
            :services="services"
            :barbershops="barbershops"
            :default-barbershop-id="barber.barbershop_id ?? 0"
        />
    </div>
</template>

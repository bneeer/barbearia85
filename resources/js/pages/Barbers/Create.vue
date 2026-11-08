<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BarberForm from '@/components/BarberForm.vue';
import type { BarberService } from '@/types/barber';

defineProps<{
    services: BarberService[];
    barbershops: { id: number; name: string }[];
    defaultBarbershopId: number;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Barbeiros', href: '/barbers' },
            { title: 'Novo barbeiro', href: '/barbers/create' },
        ],
    },
});
</script>

<template>
    <Head title="Novo barbeiro" />
    <div class="max-w-xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Novo barbeiro</h1>
        <BarberForm
            :services="services"
            :barbershops="barbershops"
            :default-barbershop-id="defaultBarbershopId"
        />
    </div>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ServiceForm from '@/components/ServiceForm.vue';
import type { SupplyOption } from '@/types/service';

defineProps<{ supplies: SupplyOption[] }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Serviços', href: '/services' },
            { title: 'Novo serviço', href: '/services/create' },
        ],
    },
});
</script>

<template>
    <Head title="Novo serviço" />
    <div class="max-w-xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Novo serviço</h1>
        <ServiceForm :supplies="supplies" />
    </div>
</template>

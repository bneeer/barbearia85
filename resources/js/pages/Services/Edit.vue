<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ServiceForm from '@/components/ServiceForm.vue';
import type { Service } from '@/types/service';

defineProps<{ service: Service }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Serviços', href: '/services' },
            { title: 'Editar serviço', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Editar serviço" />
    <div class="max-w-xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Editar serviço</h1>
        <ServiceForm :service="service" />
    </div>
</template>

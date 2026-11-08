<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/ProductForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Produtos', href: '/products' },
            { title: 'Novo produto', href: '/products/create' },
        ],
    },
});
</script>

<template>
    <Head title="Novo produto" />
    <div class="max-w-xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Novo produto</h1>
        <ProductForm />
    </div>
</template>

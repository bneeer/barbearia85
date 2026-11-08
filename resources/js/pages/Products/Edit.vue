<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/ProductForm.vue';
import type { Product } from '@/types/product';

defineProps<{ product: Product }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Produtos', href: '/products' },
            { title: 'Editar produto', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Editar produto" />
    <div class="max-w-xl space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Editar produto</h1>
        <ProductForm :product="product" />
    </div>
</template>

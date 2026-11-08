<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Início', href: dashboard() }] },
});
</script>

<template>
    <Head title="Início" />
    <div class="p-6">
        <h1 class="text-2xl font-semibold">Bem-vindo à Barbearia 85</h1>
        <p class="mt-2 text-muted-foreground">
            Gerencie sua barbearia pelo menu ao lado.
        </p>
    </div>
</template>

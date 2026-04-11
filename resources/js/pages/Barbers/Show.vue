<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';

const props = defineProps<{
    barber: {
        id: number;
        name: string;
        email: string;
    };
}>();
function destroy(id: number) {
    if (confirm('Excluir?')) {
        router.delete(`/barbers/${id}`);
    }
}
</script>

<template>
    <div>Barbeiro: {{ barber.name }} Email: {{ barber.email }}</div>
    <div>
        <Link :href="`/barbers/${barber.id}/edit`">Editar</Link>
        <button @click="destroy(barber.id)">Deletar</button>
        <Link :href="`/barbers/`">Voltar</Link>
    </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    barber: {
        id: number;
        name: string;
        email: string;
    };
}>();

const form = useForm({
    name: props.barber.name,
    email: props.barber.email,
});

function submit() {
    form.put(`/barbers/${props.barber.id}`);
}
</script>

<template>
    <form @submit.prevent="submit">
        <input v-model="form.name" />
        <input v-model="form.email" />

        <button @click="submit">Atualizar</button>
        <Link :href="`/barbers/`">Voltar</Link>
    </form>
</template>

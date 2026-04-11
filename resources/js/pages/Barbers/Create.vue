<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
});

function submit() {
    form.post('/barbers');
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Barbeiros',
                href: 'barbers/',
            },
        ],
    },
});
</script>

<template>
    <div>
        <h1>Novo Barbeiro</h1>

        <form @submit.prevent="submit">
            <input v-model="form.name" placeholder="Nome" />
            <input v-model="form.email" placeholder="Email" />
            <input
                v-model="form.password"
                type="password"
                placeholder="Senha"
            />

            <button type="submit">Salvar</button>
        </form>
    </div>
</template>

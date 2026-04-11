<script setup lang="ts">
import { Link, router} from '@inertiajs/vue3';

defineProps<{
    barbers: {
        id: number;
        name: string;
        email: string;
    }[];
}>();

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
        <h1>Barbeiros</h1>

        <Link href="/barbers/create">Adicionar barbeiro</Link>

        <ul>
            <li v-for="barber in barbers" :key="barber.id">
                {{ barber.name }}

                <Link :href="`/barbers/${barber.id}`">Ver</Link>
            </li>
        </ul>
    </div>
</template>

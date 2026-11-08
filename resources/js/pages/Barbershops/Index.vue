<script setup lang="ts">
import { EditPencil, Eye, Plus, Search } from '@iconoir/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Barbershop } from '@/types/barbershop';

type PageLink = { url: string | null; label: string; active: boolean };
const props = defineProps<{
    barbershops: {
        data: Barbershop[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string };
}>();
const search = ref(props.filters.search);

function filter() {
    router.get(
        '/barbershops',
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Unidades', href: '/barbershops' }] },
});
</script>

<template>
    <Head title="Unidades" />
    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Unidades</h1>
                <p class="text-sm text-muted-foreground">
                    Gerencie as barbearias, seus horários e equipes.
                </p>
            </div>
            <Button as-child>
                <Link href="/barbershops/create">
                    <Plus aria-hidden="true" />Adicionar unidade
                </Link>
            </Button>
        </div>
        <form class="flex max-w-md gap-2" @submit.prevent="filter">
            <Input
                v-model="search"
                aria-label="Buscar unidades"
                placeholder="Buscar por nome ou endereço"
                maxlength="100"
            />
            <Button type="submit"><Search aria-hidden="true" />Buscar</Button>
        </form>
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted text-muted-foreground">
                    <tr>
                        <th class="p-4">Nome</th>
                        <th class="p-4">Endereço</th>
                        <th class="p-4">CEP</th>
                        <th class="p-4">Estado</th>
                        <th class="p-4">Barbeiros</th>
                        <th class="p-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="barbershop in barbershops.data"
                        :key="barbershop.id"
                        class="border-t"
                    >
                        <td class="p-4 font-medium">{{ barbershop.name }}</td>
                        <td class="p-4">{{ barbershop.address || '—' }}</td>
                        <td class="p-4">
                            {{
                                barbershop.zip_code?.replace(
                                    /^(\d{5})(\d{3})$/,
                                    '$1-$2',
                                ) || '—'
                            }}
                        </td>
                        <td class="p-4">
                            {{ barbershop.is_open ? 'Aberta' : 'Fechada' }}
                        </td>
                        <td class="p-4">{{ barbershop.users_count }}</td>
                        <td class="space-x-4 p-4">
                            <Link
                                class="inline-flex items-center gap-1 text-primary underline"
                                :href="`/barbershops/${barbershop.id}`"
                            >
                                <Eye aria-hidden="true" class="size-4" />Ver
                            </Link>
                            <Link
                                class="inline-flex items-center gap-1 text-primary underline"
                                :href="`/barbershops/${barbershop.id}/edit`"
                            >
                                <EditPencil
                                    aria-hidden="true"
                                    class="size-4"
                                />Editar
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!barbershops.data.length">
                        <td colspan="6" class="p-4 text-muted-foreground">
                            Nenhuma unidade encontrada.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
            <p v-if="barbershops.total">
                Exibindo {{ barbershops.from }} a {{ barbershops.to }} de
                {{ barbershops.total }}
            </p>
            <p v-else>0 resultados</p>
            <nav aria-label="Paginação" class="flex flex-wrap gap-1">
                <template
                    v-for="(link, index) in barbershops.links"
                    :key="index"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        :class="[
                            'rounded border px-3 py-1',
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        ]"
                    >
                        {{
                            index === 0
                                ? 'Anterior'
                                : index === barbershops.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}
                    </Link>
                    <span
                        v-else
                        class="rounded border px-3 py-1 text-muted-foreground"
                    >
                        {{
                            index === 0
                                ? 'Anterior'
                                : index === barbershops.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>

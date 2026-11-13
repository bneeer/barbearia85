<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrivacyPagination from '@/components/PrivacyPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ClientSummary, Page } from '@/types/privacy';

const props = defineProps<{
    clients: Page<ClientSummary>;
    filters: { search: string };
}>();
const search = ref(props.filters.search);
function filter() {
    router.get(
        '/clients',
        { search: search.value },
        { preserveState: true, replace: true },
    );
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'Clientes', href: '/clients' }] },
});
</script>

<template>
    <Head title="Clientes" />
    <main class="space-y-6 p-4 md:p-6">
        <h1 class="text-2xl font-semibold">Clientes</h1>
        <p class="text-sm text-muted-foreground">
            Cadastros de todas as unidades. Uma pessoa pode ter mais de um
            cadastro.
        </p>
        <form class="flex max-w-lg gap-2" @submit.prevent="filter">
            <Input
                v-model="search"
                aria-label="Buscar clientes"
                placeholder="Nome, e-mail ou telefone"
                maxlength="100"
            />
            <Button type="submit">Buscar</Button>
        </form>
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="p-3">Nome</th>
                        <th class="p-3">Unidade</th>
                        <th class="p-3">Contato</th>
                        <th class="p-3">Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="client in clients.data"
                        :key="client.id"
                        class="border-t"
                    >
                        <td class="p-3">
                            <Link
                                :href="`/clients/${client.id}`"
                                class="text-primary underline"
                                >{{ client.name }}</Link
                            >
                        </td>
                        <td class="p-3">{{ client.unit ?? 'Indisponível' }}</td>
                        <td class="p-3">
                            {{ client.email ?? 'Sem e-mail' }}<br />{{
                                client.phone
                            }}
                        </td>
                        <td class="p-3">
                            {{ client.archived ? 'Arquivado' : 'Ativo' }}
                        </td>
                    </tr>
                    <tr v-if="!clients.data.length">
                        <td colspan="4" class="p-3">
                            Nenhum cliente encontrado.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-sm">{{ clients.total }} cadastro(s)</p>
        <PrivacyPagination :links="clients.links" />
    </main>
</template>

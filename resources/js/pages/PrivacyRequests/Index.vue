<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PrivacyPagination from '@/components/PrivacyPagination.vue';
import { Button } from '@/components/ui/button';
import { requestTypes } from '@/types/privacy';
import type { Page, RequestSummary } from '@/types/privacy';

defineProps<{ requests: Page<RequestSummary> }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Solicitações LGPD', href: '/privacy-requests' },
        ],
    },
});
</script>

<template>
    <Head title="Solicitações LGPD" />
    <main class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Solicitações LGPD</h1>
                <p class="text-sm text-muted-foreground">
                    Acompanhe pedidos recebidos pelos canais da barbearia.
                </p>
            </div>
            <Button as-child
                ><Link href="/privacy-requests/create"
                    >Registrar solicitação</Link
                ></Button
            >
        </div>
        <p class="text-sm">
            Comunique o protocolo e a resposta pelo canal apropriado; o sistema
            não os envia automaticamente.
        </p>
        <div class="overflow-x-auto rounded border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="p-3">Protocolo</th>
                        <th class="p-3">Tipo</th>
                        <th class="p-3">Responsável</th>
                        <th class="p-3">Prazo</th>
                        <th class="p-3">Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in requests.data"
                        :key="item.id"
                        class="border-t"
                    >
                        <td class="p-3">
                            <Link
                                :href="`/privacy-requests/${item.id}`"
                                class="text-primary underline"
                                >{{ item.protocol }}</Link
                            >
                        </td>
                        <td class="p-3">
                            {{ requestTypes[item.type] ?? item.type }}
                        </td>
                        <td class="p-3">
                            {{ item.assigned_to ?? 'Não atribuído' }}
                        </td>
                        <td class="p-3">
                            {{ item.due_date }}
                            <strong v-if="item.overdue" class="text-destructive"
                                >Vencida</strong
                            >
                        </td>
                        <td class="p-3">
                            {{
                                item.status === 'open' ? 'Aberta' : 'Concluída'
                            }}
                        </td>
                    </tr>
                    <tr v-if="!requests.data.length">
                        <td colspan="5" class="p-3">
                            Nenhuma solicitação registrada.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PrivacyPagination :links="requests.links" />
    </main>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ClientMatches from '@/components/ClientMatches.vue';
import PrivacyPagination from '@/components/PrivacyPagination.vue';
import type { ClientMatch, ClientSummary, Page } from '@/types/privacy';

type Appointment = {
    id: number;
    status: string;
    archived: boolean;
    start: string;
    timezone: string | null;
    unit: string | null;
    barber: string | null;
    services: string[];
};
type LinkedRequest = {
    id: number;
    protocol: string;
    type: string;
    status: string;
};
defineProps<{
    client: ClientSummary & { notes: string | null };
    appointments: Page<Appointment>;
    matches: ClientMatch[];
    requests: LinkedRequest[];
}>();
const statuses: Record<string, string> = {
    scheduled: 'Agendado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
    no_show: 'Falta',
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clientes', href: '/clients' },
            { title: 'Detalhes', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Detalhes do cliente" />
    <main class="space-y-8 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">{{ client.name }}</h1>
            <p>
                Cadastro nº {{ client.id }} · {{ client.unit }}
                <span v-if="client.archived">· Cadastro arquivado</span>
            </p>
        </div>
        <dl class="space-y-2 text-sm">
            <div>
                <dt class="font-semibold">E-mail</dt>
                <dd>{{ client.email ?? 'Não informado' }}</dd>
            </div>
            <div>
                <dt class="font-semibold">Telefone</dt>
                <dd>{{ client.phone }}</dd>
            </div>
            <div v-if="client.notes">
                <dt class="font-semibold">Observações</dt>
                <dd class="whitespace-pre-wrap">{{ client.notes }}</dd>
            </div>
        </dl>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">Histórico de agendamentos</h2>
            <div class="overflow-x-auto rounded border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="p-3">Data e unidade</th>
                            <th class="p-3">Serviços</th>
                            <th class="p-3">Profissional</th>
                            <th class="p-3">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="appointment in appointments.data"
                            :key="appointment.id"
                            class="border-t"
                        >
                            <td class="p-3">
                                {{ appointment.start }}<br />{{
                                    appointment.timezone
                                }}
                                · {{ appointment.unit }}
                            </td>
                            <td class="p-3">
                                {{ appointment.services.join(', ') }}
                            </td>
                            <td class="p-3">{{ appointment.barber }}</td>
                            <td class="p-3">
                                {{
                                    statuses[appointment.status] ??
                                    appointment.status
                                }}<span v-if="appointment.archived">
                                    · Arquivado</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!appointments.data.length">
                            <td colspan="4" class="p-3">
                                Nenhum agendamento encontrado.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <PrivacyPagination :links="appointments.links" />
        </section>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">
                Possíveis cadastros relacionados
            </h2>
            <ClientMatches :matches="matches" />
        </section>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">Solicitações vinculadas</h2>
            <ul v-if="requests.length" class="space-y-2">
                <li v-for="request in requests" :key="request.id">
                    <Link
                        :href="`/privacy-requests/${request.id}`"
                        class="text-primary underline"
                        >{{ request.protocol }}</Link
                    >
                    · {{ request.status === 'open' ? 'Aberta' : 'Concluída' }}
                </li>
            </ul>
            <p v-else>Nenhuma solicitação vinculada.</p>
            <Link
                href="/privacy-requests/create"
                class="inline-block text-primary underline"
                >Registrar solicitação</Link
            >
        </section>
    </main>
</template>

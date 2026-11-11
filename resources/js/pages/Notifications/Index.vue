<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Delivery = {
    id: number;
    recipient: string | null;
    barbershop_id: number | null;
    audience: string | null;
    template: string;
    status: string;
    delivery_status: string | null;
    failure_code: string | null;
    provider_id: string | null;
    attempts: number;
    created_at: string;
    next_attempt_at: string | null;
    can_retry: boolean;
};
type Details = Delivery & {
    attempts_history: {
        id: number;
        number: number;
        result: string;
        code: string | null;
        requested_by: number | null;
        created_at: string;
    }[];
    webhooks: { event_id: string; type: string; occurred_at: string }[];
};
const props = defineProps<{
    notifications: {
        data: Delivery[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: {
        recipient?: string;
        status?: string;
        template?: string;
        barbershop_id?: number | string;
        from?: string;
        to?: string;
    };
    barbershops: { id: number; name: string }[];
    emailEnabled: boolean;
}>();
const filters = ref({
    recipient: '',
    status: '',
    template: '',
    barbershop_id: '',
    from: '',
    to: '',
    ...props.filters,
});
const details = ref<Details | null>(null);
const error = ref('');
const busy = ref(false);
const labels: Record<string, string> = {
    pending: 'Pendente',
    processing: 'Processando',
    sent: 'Aceito pelo servidor SMTP',
    failed: 'Falha',
    skipped: 'Ignorado',
    simulated: 'Simulação',
    accepted: 'Aceito',
    delayed: 'Entrega atrasada',
    delivered: 'Entregue',
    rejected: 'Rejeitado',
    bounced: 'Devolvido',
    complained: 'Reclamação',
    verify_email: 'Verificação de conta',
    reset_password: 'Recuperação de senha',
    password_changed: 'Senha alterada',
    booking_confirmation: 'Confirmação',
    booking_rescheduled: 'Reagendamento',
    booking_cancellation: 'Cancelamento',
    booking_cancellation_request: 'Solicitação de cancelamento',
    client: 'Cliente',
    barber: 'Barbeiro',
    admin: 'Administrador',
    account: 'Conta',
    activation_cutoff: 'Anterior à ativação',
    email_disabled: 'Canal desativado',
    expired: 'Validade encerrada',
    superseded: 'Evento substituído',
    rate_limited: 'Limite de envio atingido',
    provider_unavailable: 'Provedor indisponível',
    provider_rejected: 'Solicitação rejeitada pelo provedor',
    provider_result_unknown: 'Resultado incerto; requer reconciliação',
    reconciliation_required: 'Resultado incerto; requer análise',
    missing_api_key: 'Chave do provedor não configurada',
    smtp_temporary_rejection: 'Recusa temporária do servidor SMTP',
    smtp_rejected: 'Recusado pelo servidor SMTP',
    smtp_result_unknown: 'Resultado SMTP incerto; requer análise',
    smtp_send_cancelled: 'Envio cancelado antes da confirmação',
    unsupported_mailer: 'Mailer incompatível',
    internal_error: 'Falha interna; requer análise',
    account_changed: 'Conta alterada ou verificada',
    attempts_exhausted: 'Tentativas esgotadas',
    worker_interrupted: 'Worker interrompido',
    retry_requested: 'Reprocessamento solicitado',
    retry_scheduled: 'Nova tentativa agendada',
    interrupted: 'Interrompido',
};
const label = (value: string | null) =>
    value ? (labels[value] ?? value) : '—';
const statusLabel = (delivery: Delivery) =>
    delivery.status === 'sent' &&
    delivery.provider_id &&
    !delivery.provider_id.endsWith('@notifications.barbearia85.local')
        ? 'Aceito pelo mecanismo anterior'
        : label(delivery.status);
const date = (value: string | null) =>
    value ? new Date(value).toLocaleString('pt-BR') : '—';
function filter() {
    router.get('/notifications', filters.value, {
        preserveState: true,
        replace: true,
        onError: (errors) => {
            error.value = Object.values(errors).join(' ');
        },
    });
}
async function show(id: number) {
    busy.value = true;
    error.value = '';

    try {
        const response = await fetch(`/notifications/${id}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Não foi possível carregar o histórico.');
        }

        details.value = await response.json();
    } catch (failure) {
        error.value =
            failure instanceof Error
                ? failure.message
                : 'Falha ao carregar o histórico.';
    } finally {
        busy.value = false;
    }
}
function retry(delivery: Delivery) {
    if (
        !window.confirm(
            `Reprocessar o envio #${delivery.id}? A causa da falha deve estar resolvida.`,
        )
    ) {
        return;
    }

    router.post(
        `/notifications/${delivery.id}/retry`,
        {},
        {
            onSuccess: () => {
                details.value = null;
            },
            onError: (errors) => {
                error.value = Object.values(errors).join(' ');
            },
        },
    );
}
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notificações', href: '/notifications' }],
    },
});
</script>

<template>
    <Head title="Notificações" />
    <div class="space-y-6 p-4 md:p-6">
        <h1 class="text-2xl font-semibold">Notificações por e-mail</h1>
        <p class="text-sm text-muted-foreground">
            Aceite pelo servidor SMTP não confirma entrega na caixa de entrada.
            SMTP não fornece webhooks de entrega. Links e conteúdo privado não
            são exibidos.
        </p>
        <p v-if="!emailEnabled" role="status" class="rounded-lg border p-4">
            O envio de e-mails está desativado.
        </p>
        <p v-if="error" role="alert" class="text-destructive">{{ error }}</p>
        <form class="grid gap-3 md:grid-cols-4" @submit.prevent="filter">
            <label class="space-y-1 text-sm"
                >Destinatário<Input
                    v-model="filters.recipient"
                    type="search"
                    maxlength="255"
            /></label>
            <label class="space-y-1 text-sm"
                >Processamento<select
                    v-model="filters.status"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option value="">Todos</option>
                    <option
                        v-for="state in [
                            'pending',
                            'processing',
                            'sent',
                            'failed',
                            'skipped',
                            'simulated',
                        ]"
                        :key="state"
                        :value="state"
                    >
                        {{ label(state) }}
                    </option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Evento<select
                    v-model="filters.template"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option value="">Todos</option>
                    <option
                        v-for="event in [
                            'verify_email',
                            'reset_password',
                            'password_changed',
                            'booking_confirmation',
                            'booking_rescheduled',
                            'booking_cancellation',
                            'booking_cancellation_request',
                        ]"
                        :key="event"
                        :value="event"
                    >
                        {{ label(event) }}
                    </option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Unidade<select
                    v-model="filters.barbershop_id"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option value="">Todas / autenticação</option>
                    <option
                        v-for="shop in barbershops"
                        :key="shop.id"
                        :value="shop.id"
                    >
                        {{ shop.name }}
                    </option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >De<Input v-model="filters.from" type="date"
            /></label>
            <label class="space-y-1 text-sm"
                >Até<Input v-model="filters.to" type="date"
            /></label>
            <Button class="self-end" type="submit">Filtrar</Button>
        </form>
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="p-3">Envio / evento</th>
                        <th class="p-3">Destinatário</th>
                        <th class="p-3">Unidade</th>
                        <th class="p-3">Estado</th>
                        <th class="p-3">Tentativas / próximo envio</th>
                        <th class="p-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in notifications.data"
                        :key="item.id"
                        class="border-t"
                    >
                        <td class="p-3">
                            #{{ item.id }} · {{ label(item.template) }}<br />{{
                                date(item.created_at)
                            }}
                        </td>
                        <td class="p-3">
                            {{ item.recipient ?? 'Registro legado' }}<br />{{
                                label(item.audience)
                            }}
                        </td>
                        <td class="p-3">
                            {{
                                barbershops.find(
                                    (shop) => shop.id === item.barbershop_id,
                                )?.name ?? '—'
                            }}
                        </td>
                        <td class="p-3">
                            {{ statusLabel(item) }}<br />{{
                                label(item.delivery_status)
                            }}<br /><span class="text-muted-foreground">{{
                                label(item.failure_code)
                            }}</span>
                        </td>
                        <td class="p-3">
                            {{ item.attempts }}<br />{{
                                date(item.next_attempt_at)
                            }}
                        </td>
                        <td class="space-y-2 p-3">
                            <Button
                                variant="outline"
                                :disabled="busy"
                                @click="show(item.id)"
                                >Histórico</Button
                            ><Button
                                v-if="item.can_retry"
                                variant="outline"
                                @click="retry(item)"
                                >Reprocessar</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="!notifications.data.length">
                        <td colspan="6" class="p-4">
                            Nenhuma notificação encontrada.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav aria-label="Paginação" class="flex flex-wrap gap-2">
            <template v-for="(link, index) in notifications.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2"
                    :aria-current="link.active ? 'page' : undefined"
                    ><span v-html="link.label"
                /></Link>
            </template>
        </nav>
        <section
            v-if="details"
            aria-label="Histórico do envio"
            class="space-y-3 rounded-lg border p-4"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">
                    Histórico #{{ details.id }}
                </h2>
                <Button variant="outline" @click="details = null"
                    >Fechar</Button
                >
            </div>
            <p class="text-sm break-all">
                Identificador da mensagem: {{ details.provider_id ?? '—' }}
            </p>
            <ul class="space-y-2 text-sm">
                <li
                    v-for="attempt in details.attempts_history"
                    :key="attempt.id"
                >
                    {{ date(attempt.created_at) }} · tentativa
                    {{ attempt.number }} ·
                    {{
                        attempt.result === 'sent'
                            ? statusLabel(details)
                            : label(attempt.result)
                    }}
                    · {{ label(attempt.code)
                    }}<span v-if="attempt.requested_by">
                        · administrador #{{ attempt.requested_by }}</span
                    >
                </li>
            </ul>
            <p v-if="!details.attempts_history.length" class="text-sm">
                Sem tentativas registradas.
            </p>
            <h3 v-if="details.webhooks.length" class="font-medium">
                Eventos históricos do mecanismo anterior
            </h3>
            <ul v-if="details.webhooks.length" class="space-y-2 text-sm">
                <li v-for="event in details.webhooks" :key="event.event_id">
                    {{ date(event.occurred_at) }} · {{ event.type }}
                </li>
            </ul>
        </section>
    </div>
</template>

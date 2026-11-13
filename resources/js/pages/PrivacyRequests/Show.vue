<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import ClientMatches from '@/components/ClientMatches.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { channels, requestTypes, results } from '@/types/privacy';
import type { Admin, ClientMatch } from '@/types/privacy';

type Item = {
    id: number;
    protocol: string;
    type: string;
    other_type: string | null;
    channel: string;
    received_at: string;
    due_date: string;
    suggested_due_date: string | null;
    due_reason: string | null;
    assigned_to: number | null;
    status: string;
    identity_verified: boolean;
    identity_method: string | null;
    internal_search_done: boolean;
    external_search_done: boolean;
    shared_agents_done: boolean;
    response_sent: boolean;
    result: string | null;
    result_reason: string | null;
    retained_categories: string | null;
    completed_at: string | null;
};
type Preview = {
    appointments: number;
    future_scheduled: number;
    checkouts: number;
    payments: number;
    linked_notifications: number;
};
type Client = {
    id: number;
    name: string;
    unit: string | null;
    archived: boolean;
    matches: ClientMatch[];
    preview: Preview | null;
};
type Event = {
    event: string;
    actor: string | null;
    created_at: string;
    details: Record<string, unknown> | null;
};
const props = defineProps<{
    item: Item;
    clients: Client[];
    admins: Admin[];
    events: Event[];
}>();
const form = useForm({
    assigned_to: String(props.item.assigned_to ?? ''),
    due_date: props.item.due_date,
    due_reason: props.item.due_reason ?? '',
    identity_verified: props.item.identity_verified,
    identity_method: props.item.identity_method ?? '',
    internal_search_done: props.item.internal_search_done,
    external_search_done: props.item.external_search_done,
    shared_agents_done: props.item.shared_agents_done,
    response_sent: props.item.response_sent,
});
const linkForm = useForm({ client_id: '' });
const unlinkForm = useForm({ client_id: '' });
const completion = useForm({
    result: '',
    result_reason: '',
    retained_categories: '',
});
const reopenForm = useForm({ reason: '' });
const eventsText: Record<string, string> = {
    created: 'Solicitação registrada',
    updated: 'Acompanhamento atualizado',
    client_linked: 'Cadastro vinculado',
    client_unlinked: 'Vínculo removido',
    deletion_blocked: 'Exclusão bloqueada: retenção indefinida',
    completed: 'Resultado registrado',
    reopened: 'Solicitação reaberta',
};
watch(
    () => props.item,
    (item) => {
        form.identity_verified = item.identity_verified;
        form.identity_method = item.identity_method ?? '';
        form.internal_search_done = item.internal_search_done;
        form.external_search_done = item.external_search_done;
        form.shared_agents_done = item.shared_agents_done;
        form.response_sent = item.response_sent;
    },
);
function save() {
    form.patch(`/privacy-requests/${props.item.id}`, { preserveScroll: true });
}
function link() {
    linkForm.post(`/privacy-requests/${props.item.id}/clients`, {
        preserveScroll: true,
        onSuccess: () => linkForm.reset(),
    });
}
function finish() {
    completion.post(`/privacy-requests/${props.item.id}/complete`, {
        preserveScroll: true,
    });
}
function reopen() {
    reopenForm.post(`/privacy-requests/${props.item.id}/reopen`, {
        preserveScroll: true,
        onSuccess: () => reopenForm.reset(),
    });
}
function unlink(client: Client) {
    if (
        confirm(
            `Remover o vínculo com o cadastro nº ${client.id}? Nenhum dado do cliente será excluído.`,
        )
    ) {
        unlinkForm.delete(
            `/privacy-requests/${props.item.id}/clients/${client.id}`,
            {
                preserveScroll: true,
            },
        );
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Solicitações LGPD', href: '/privacy-requests' },
            { title: 'Detalhes', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Detalhes da solicitação" />
    <main class="mx-auto max-w-4xl space-y-8 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">{{ item.protocol }}</h1>
            <p>
                {{ requestTypes[item.type] }} ·
                {{ item.status === 'open' ? 'Aberta' : 'Concluída' }}
            </p>
            <p v-if="item.other_type">
                Direito solicitado: {{ item.other_type }}
            </p>
        </div>
        <p class="text-sm">
            Recebida em {{ item.received_at }} (horário de Brasília) por
            {{ channels[item.channel] }}. Comunique o protocolo e a resposta
            manualmente.
        </p>
        <p v-if="item.suggested_due_date" class="text-sm">
            Prazo sugerido para declaração completa:
            {{ item.suggested_due_date }}. A data efetiva deve ser validada
            juridicamente.
        </p>
        <p v-if="item.type === 'simple_access'" class="text-sm font-semibold">
            A resposta simplificada de confirmação ou acesso deve ser imediata.
        </p>
        <p
            v-if="item.type === 'deletion'"
            class="rounded border border-destructive/50 p-4 text-sm"
        >
            Exclusão definitiva bloqueada: a retenção ainda não foi definida. A
            solicitação deve continuar a ser analisada e respondida.
        </p>
        <section
            v-if="item.status === 'open'"
            class="space-y-4 rounded-lg border p-4"
        >
            <h2 class="text-xl font-semibold">Acompanhamento</h2>
            <p class="text-sm text-muted-foreground">
                Assinale apenas etapas efetivamente realizadas ou avaliadas. Não
                inclua cópias de documentos, conteúdo da resposta ou dados
                sensíveis.
            </p>
            <form class="space-y-4" @submit.prevent="save">
                <div class="space-y-2">
                    <Label for="assigned">Responsável</Label
                    ><select
                        id="assigned"
                        v-model="form.assigned_to"
                        class="w-full rounded-md border bg-background p-2"
                        required
                    >
                        <option value="" disabled>Selecione</option>
                        <option
                            v-for="admin in admins"
                            :key="admin.id"
                            :value="String(admin.id)"
                        >
                            {{ admin.name }}
                        </option></select
                    ><InputError :message="form.errors.assigned_to" />
                </div>
                <div class="space-y-2">
                    <Label for="due">Prazo de acompanhamento</Label
                    ><Input
                        id="due"
                        v-model="form.due_date"
                        type="date"
                        required
                    /><InputError :message="form.errors.due_date" />
                </div>
                <div class="space-y-2">
                    <Label for="due-reason"
                        >Justificativa de alteração do prazo</Label
                    ><Input
                        id="due-reason"
                        v-model="form.due_reason"
                        maxlength="500"
                    /><InputError :message="form.errors.due_reason" />
                </div>
                <label class="flex items-start gap-2"
                    ><input
                        v-model="form.identity_verified"
                        type="checkbox"
                        class="mt-1"
                    />
                    Identidade verificada de forma proporcional</label
                ><InputError :message="form.errors.identity_verified" />
                <div v-if="form.identity_verified" class="space-y-2">
                    <Label for="method">Método de verificação</Label
                    ><select
                        id="method"
                        v-model="form.identity_method"
                        class="w-full rounded-md border bg-background p-2"
                    >
                        <option value="">Selecione</option>
                        <option value="existing_contact">
                            Contato previamente cadastrado
                        </option>
                        <option value="in_person">Presencial</option>
                        <option value="other">
                            Outro método proporcional
                        </option></select
                    ><InputError :message="form.errors.identity_method" />
                </div>
                <label class="flex items-start gap-2"
                    ><input
                        v-model="form.internal_search_done"
                        type="checkbox"
                        class="mt-1"
                    />
                    Busca em cadastros, agendamentos e notificações
                    realizada</label
                >
                <label class="flex items-start gap-2"
                    ><input
                        v-model="form.external_search_done"
                        type="checkbox"
                        class="mt-1"
                    />
                    Sistemas externos, exportações e backups avaliados</label
                >
                <label class="flex items-start gap-2"
                    ><input
                        v-model="form.shared_agents_done"
                        type="checkbox"
                        class="mt-1"
                    />
                    Comunicação aos agentes de compartilhamento realizada ou
                    avaliada conforme aplicável</label
                >
                <label class="flex items-start gap-2"
                    ><input
                        v-model="form.response_sent"
                        type="checkbox"
                        class="mt-1"
                    />
                    Resposta comunicada ao titular</label
                >
                <Button type="submit" :disabled="form.processing"
                    >Salvar acompanhamento</Button
                >
            </form>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Cadastros confirmados</h2>
            <p class="text-sm text-muted-foreground">
                A verificação de identidade deve ser salva antes de vincular.
                Confira cada cadastro separadamente; não há união automática.
            </p>
            <form
                v-if="item.status === 'open'"
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="link"
            >
                <div class="space-y-2">
                    <Label for="client-id">Número do cadastro</Label
                    ><Input
                        id="client-id"
                        v-model="linkForm.client_id"
                        type="number"
                        min="1"
                        required
                    /><InputError :message="linkForm.errors.client_id" />
                </div>
                <Button type="submit" :disabled="linkForm.processing"
                    >Vincular cadastro confirmado</Button
                ><Link
                    href="/clients"
                    class="py-2 text-sm text-primary underline"
                    >Buscar cadastro</Link
                >
            </form>
            <p v-if="!clients.length">Nenhum cadastro vinculado.</p>
            <article
                v-for="client in clients"
                :key="client.id"
                class="space-y-4 rounded border p-4"
            >
                <h3 class="font-semibold">
                    <Link
                        :href="`/clients/${client.id}`"
                        class="text-primary underline"
                        >{{ client.name }}</Link
                    >
                    · {{ client.unit }}
                    <span v-if="client.archived">· Arquivado</span>
                </h3>
                <Button
                    v-if="item.status === 'open'"
                    type="button"
                    variant="outline"
                    :disabled="unlinkForm.processing"
                    @click="unlink(client)"
                >
                    Remover vínculo
                </Button>
                <InputError :message="unlinkForm.errors.client_id" />
                <ClientMatches :matches="client.matches" />
                <div
                    v-if="client.preview"
                    class="space-y-2 rounded border border-destructive/50 p-3 text-sm"
                >
                    <h4 class="font-semibold">
                        Prévia de exclusão — bloqueada
                    </h4>
                    <p>
                        Retenção ainda não definida. Nenhum dado foi excluído.
                        Ação definitiva exige regras validadas e implementação
                        posterior.
                    </p>
                    <p>
                        Agendamentos: {{ client.preview.appointments }}; futuros
                        ativos: {{ client.preview.future_scheduled }};
                        fechamentos: {{ client.preview.checkouts }}; pagamentos:
                        {{ client.preview.payments }}; notificações vinculadas:
                        {{ client.preview.linked_notifications }}.
                    </p>
                    <p v-if="client.preview.future_scheduled">
                        Resolva os agendamentos futuros separadamente. Cancelar
                        não apaga os dados.
                    </p>
                    <p>
                        Solicitações de cancelamento, mensagens por
                        destinatário, logs, filas, exportações, backups e
                        fornecedores podem conter dados sem vínculo direto;
                        investigar manualmente. A prévia não apaga ou verifica
                        esses sistemas.
                    </p>
                </div>
            </article>
        </section>
        <section
            v-if="item.status === 'open'"
            class="space-y-4 rounded-lg border p-4"
        >
            <h2 class="text-xl font-semibold">Registrar resultado</h2>
            <p class="text-sm">
                Antes de concluir, salve as etapas acima e comunique a resposta
                ao titular. Para exclusão, informe as categorias preservadas e a
                justificativa; não é possível registrar exclusão integral nesta
                etapa.
            </p>
            <form class="space-y-4" @submit.prevent="finish">
                <div class="space-y-2">
                    <Label for="result">Resultado</Label
                    ><select
                        id="result"
                        v-model="completion.result"
                        required
                        class="w-full rounded-md border bg-background p-2"
                    >
                        <option value="">Selecione</option>
                        <option
                            v-for="(label, value) in results"
                            :key="value"
                            :value="value"
                            :disabled="
                                item.type === 'deletion' &&
                                value === 'fulfilled'
                            "
                        >
                            {{ label }}
                        </option></select
                    ><InputError :message="completion.errors.result" />
                </div>
                <div class="space-y-2">
                    <Label for="result-reason">Justificativa do resultado</Label
                    ><Input
                        id="result-reason"
                        v-model="completion.result_reason"
                        maxlength="500"
                    /><InputError :message="completion.errors.result_reason" />
                </div>
                <div class="space-y-2">
                    <Label for="retained"
                        >Categorias preservadas ou pendentes</Label
                    ><Input
                        id="retained"
                        v-model="completion.retained_categories"
                        maxlength="500"
                    /><InputError
                        :message="completion.errors.retained_categories"
                    />
                </div>
                <Button type="submit" :disabled="completion.processing"
                    >Registrar conclusão</Button
                >
            </form>
        </section>
        <section v-else class="space-y-2 rounded border p-4">
            <h2 class="text-xl font-semibold">Resultado</h2>
            <p>{{ results[item.result ?? ''] }} · {{ item.result_reason }}</p>
            <p v-if="item.retained_categories">
                Categorias preservadas ou pendentes:
                {{ item.retained_categories }}
            </p>
            <p>Prazo: {{ item.due_date }} · {{ item.due_reason }}</p>
            <form
                v-if="item.result !== 'fulfilled'"
                class="space-y-3 pt-3"
                @submit.prevent="reopen"
            >
                <Label for="reopen-reason">Motivo para reabrir</Label>
                <Input
                    id="reopen-reason"
                    v-model="reopenForm.reason"
                    maxlength="500"
                    required
                />
                <InputError :message="reopenForm.errors.reason" />
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="reopenForm.processing"
                    >Reabrir acompanhamento</Button
                >
            </form>
        </section>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">Histórico do protocolo</h2>
            <ul class="space-y-2">
                <li
                    v-for="(event, index) in events"
                    :key="index"
                    class="rounded border p-3 text-sm"
                >
                    <p>
                        {{ event.created_at }} ·
                        {{ event.actor ?? 'Usuário indisponível' }} ·
                        {{ eventsText[event.event] ?? event.event }}
                    </p>
                    <p v-if="event.details?.due_reason">
                        Justificativa de prazo: {{ event.details.due_reason }}
                    </p>
                    <p v-if="event.details?.result_reason">
                        Justificativa de resultado:
                        {{ event.details.result_reason }}
                    </p>
                    <p v-if="event.details?.result">
                        Resultado:
                        {{
                            results[String(event.details.result)] ??
                            event.details.result
                        }}
                    </p>
                    <p v-if="event.details?.retained_categories">
                        Categorias preservadas:
                        {{ event.details.retained_categories }}
                    </p>
                    <p v-if="event.details?.changed_fields">
                        Campos atualizados:
                        {{
                            Array.isArray(event.details.changed_fields)
                                ? event.details.changed_fields.join(', ')
                                : ''
                        }}
                    </p>
                    <p v-if="event.details?.client_id">
                        Cadastro nº {{ event.details.client_id }}
                    </p>
                    <p
                        v-if="
                            event.details?.reason && event.event === 'reopened'
                        "
                    >
                        Motivo: {{ event.details.reason }}
                    </p>
                </li>
            </ul>
        </section>
    </main>
</template>

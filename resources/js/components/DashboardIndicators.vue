<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/dateTime';
import { formatMoney } from '@/lib/money';
import type {
    DashboardSummary,
    ScheduledAppointmentPage,
} from '@/types/dashboard';

const props = defineProps<{
    summary: DashboardSummary;
    barbershopId?: string;
    showFinancials?: boolean;
}>();
const { isAdmin } = useAuth();
const { getInitials } = useInitials();
const open = ref(false);
const selectedBarber = ref<{ id: number; name: string } | null>(null);
const appointments = ref<ScheduledAppointmentPage | null>(null);
const loading = ref(false);
const error = ref('');
const currentPage = ref(1);
let controller: AbortController | null = null;
const paymentLabels: Record<string, string> = {
    credit_card: 'Cartão de crédito',
    debit_card: 'Cartão de débito',
    pix: 'Pix',
    cash: 'Dinheiro',
};
const paymentMethods = computed(() => {
    const current = props.summary.current.financials?.payment_methods ?? {};
    const previous = props.summary.previous.financials?.payment_methods ?? {};

    return [...new Set([...Object.keys(current), ...Object.keys(previous)])]
        .sort()
        .map((key) => ({
            key,
            current: current[key] ?? { count: 0, total_cents: 0 },
            previous: previous[key] ?? { count: 0, total_cents: 0 },
        }));
});

function rangeQuery(barberId?: number) {
    const query = new URLSearchParams({
        range_from: props.summary.from,
        range_to: props.summary.to,
    });

    if (props.barbershopId) {
        query.set('barbershop_id', props.barbershopId);
    }

    if (barberId) {
        query.set('barber_id', String(barberId));
    }

    return query;
}

function completedUrl(barberId?: number) {
    return `/dashboard?${rangeQuery(barberId)}`;
}

async function loadScheduled(page: number) {
    controller?.abort();
    const request = new AbortController();
    controller = request;
    loading.value = true;
    error.value = '';
    currentPage.value = page;
    appointments.value = null;

    try {
        const query = rangeQuery(selectedBarber.value?.id);
        query.set('page', String(page));
        const response = await fetch(`/dashboard/scheduled?${query}`, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });

        if (!response.ok) {
            throw new Error('Não foi possível consultar os agendamentos.');
        }

        const result: ScheduledAppointmentPage = await response.json();

        if (!request.signal.aborted) {
            appointments.value = result;
        }
    } catch (cause) {
        if (!request.signal.aborted) {
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'Não foi possível consultar os agendamentos.';
        }
    } finally {
        if (controller === request) {
            loading.value = false;
        }
    }
}

function showScheduled(barber?: { id: number; name: string }) {
    selectedBarber.value = barber ?? null;
    open.value = true;
    loadScheduled(1);
}

function setOpen(value: boolean) {
    open.value = value;

    if (!value) {
        controller?.abort();
    }
}

function formatDate(date: string) {
    return new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' }).format(
        new Date(`${date}T12:00:00Z`),
    );
}

function minutes(value: number) {
    const hours = Math.floor(value / 60);
    const remainder = value % 60;

    return hours ? `${hours}h ${remainder}min` : `${remainder} min`;
}

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="space-y-4" aria-labelledby="range-indicators-title">
        <div>
            <h2 id="range-indicators-title" class="text-xl font-semibold">
                Resumo do período
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ formatDate(summary.from) }} a {{ formatDate(summary.to) }}.
                Comparação: {{ formatDate(summary.previous_from) }} a
                {{ formatDate(summary.previous_to) }}.
                {{
                    isAdmin
                        ? 'Todas as unidades incluídas no filtro.'
                        : 'Somente seus atendimentos.'
                }}
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardHeader><CardTitle>Concluídos</CardTitle></CardHeader>
                <CardContent>
                    <Link
                        :href="completedUrl()"
                        class="text-3xl font-semibold text-primary underline-offset-4 hover:underline"
                        preserve-scroll
                        preserve-state
                        >{{ summary.current.completed }}</Link
                    >
                    <p class="text-sm text-muted-foreground">
                        Anterior: {{ summary.previous.completed }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader><CardTitle>Agendados</CardTitle></CardHeader>
                <CardContent>
                    <button
                        type="button"
                        class="text-3xl font-semibold underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        @click="showScheduled()"
                    >
                        {{ summary.current.scheduled }}
                    </button>
                    <p class="text-sm text-muted-foreground">
                        Anterior: {{ summary.previous.scheduled }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader><CardTitle>Cancelados</CardTitle></CardHeader>
                <CardContent
                    ><p class="text-3xl font-semibold">
                        {{ summary.current.cancelled }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Anterior: {{ summary.previous.cancelled }}
                    </p></CardContent
                >
            </Card>
            <Card>
                <CardHeader><CardTitle>Faltas</CardTitle></CardHeader>
                <CardContent
                    ><p class="text-3xl font-semibold">
                        {{ summary.current.no_show }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Anterior: {{ summary.previous.no_show }}
                    </p></CardContent
                >
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader
                    ><CardTitle
                        >Ocupação e capacidade livre</CardTitle
                    ></CardHeader
                >
                <CardContent v-if="summary.current.capacity" class="space-y-3">
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ summary.current.capacity.occupancy_percent }}%
                    </p>
                    <dl class="grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Disponível</dt>
                            <dd class="font-medium">
                                {{
                                    minutes(
                                        summary.current.capacity
                                            .available_minutes,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Ocupado</dt>
                            <dd class="font-medium">
                                {{
                                    minutes(
                                        summary.current.capacity
                                            .occupied_minutes,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Livre</dt>
                            <dd class="font-medium">
                                {{
                                    minutes(
                                        summary.current.capacity.free_minutes,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground">
                        Capacidade consolidada por barbeiro entre todas as
                        unidades; horários bloqueados são descontados. Período
                        anterior:
                        {{
                            summary.previous.capacity?.occupancy_percent ?? 0
                        }}%.
                    </p>
                    <div
                        v-if="summary.current.capacity.barbers.length"
                        class="overflow-x-auto"
                    >
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                Ocupação por barbeiro
                            </caption>
                            <thead>
                                <tr class="border-b text-muted-foreground">
                                    <th scope="col" class="p-2">Barbeiro</th>
                                    <th scope="col" class="p-2">Ocupação</th>
                                    <th scope="col" class="p-2">Anterior</th>
                                    <th scope="col" class="p-2">Livre</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="barber in summary.current.capacity
                                        .barbers"
                                    :key="barber.id"
                                    class="border-b"
                                >
                                    <th scope="row" class="p-2 font-medium">
                                        {{ barber.name }}
                                    </th>
                                    <td class="p-2">
                                        {{ barber.occupancy_percent }}%
                                    </td>
                                    <td class="p-2">
                                        {{
                                            summary.previous.capacity?.barbers.find(
                                                (item) => item.id === barber.id,
                                            )?.occupancy_percent ?? 0
                                        }}%
                                    </td>
                                    <td class="p-2">
                                        {{ minutes(barber.free_minutes) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
                <CardContent v-else class="text-sm text-muted-foreground">
                    A ocupação não é segmentada por unidade porque os horários
                    de trabalho são globais por barbeiro. Cancelamentos e faltas
                    permanecem filtrados pela unidade selecionada.
                </CardContent>
            </Card>
            <Card>
                <CardHeader
                    ><CardTitle>Clientes atendidos</CardTitle></CardHeader
                >
                <CardContent class="space-y-3">
                    <p class="text-3xl font-semibold">
                        {{ summary.current.retention.unique_clients }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ summary.current.retention.new_clients }} novos ·
                        {{ summary.current.retention.returning_clients }}
                        recorrentes
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Recorrentes já tinham um atendimento concluído antes do
                        início do período. Comparação anterior:
                        {{ summary.previous.retention.unique_clients }} clientes
                        ({{ summary.previous.retention.new_clients }} novos e
                        {{ summary.previous.retention.returning_clients }}
                        recorrentes).
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card v-if="isAdmin && showFinancials">
            <CardHeader
                ><CardTitle>Fechamentos registrados</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Ticket médio
                        </p>
                        <p class="text-xl font-semibold">
                            {{
                                formatMoney(
                                    summary.current.financials
                                        ?.average_ticket_cents,
                                )
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Anterior:
                            {{
                                formatMoney(
                                    summary.previous.financials
                                        ?.average_ticket_cents,
                                )
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Descontos registrados
                        </p>
                        <p class="text-xl font-semibold">
                            {{
                                formatMoney(
                                    summary.current.financials?.discount_cents,
                                )
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Anterior:
                            {{
                                formatMoney(
                                    summary.previous.financials?.discount_cents,
                                )
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Fechamentos considerados
                        </p>
                        <p class="text-xl font-semibold">
                            {{
                                summary.current.financials?.checkout_count ?? 0
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Anterior:
                            {{
                                summary.previous.financials?.checkout_count ?? 0
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <p
                        v-for="method in paymentMethods"
                        :key="method.key"
                        class="rounded-md border px-3 py-2"
                    >
                        {{ paymentLabels[method.key] ?? method.key }}:
                        {{ method.current.count }} ·
                        {{ formatMoney(method.current.total_cents) }}
                        <span class="block text-xs text-muted-foreground">
                            Anterior: {{ method.previous.count }} ·
                            {{ formatMoney(method.previous.total_cents) }}
                        </span>
                    </p>
                    <p
                        v-if="!paymentMethods.length"
                        class="text-muted-foreground"
                    >
                        Nenhum fechamento registrado no período.
                    </p>
                </div>
                <p class="text-xs text-muted-foreground">
                    Valores de fechamento administrativo; não confirmam
                    processamento nem recebimento do pagamento.
                </p>
            </CardContent>
        </Card>

        <section
            v-if="isAdmin"
            class="space-y-3"
            aria-labelledby="barber-range-title"
        >
            <div>
                <h2 id="barber-range-title" class="text-xl font-semibold">
                    Indicadores por barbeiro
                </h2>
                <p class="text-sm text-muted-foreground">
                    Contagens do período selecionado. Valores de fechamento
                    somente com a opção financeira ativada.
                </p>
            </div>
            <div
                v-if="summary.barbers.length"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <Card
                    v-for="barber in summary.barbers"
                    :key="barber.id"
                    class="min-w-0"
                >
                    <CardHeader class="flex flex-row items-center gap-3">
                        <Avatar class="size-12 shrink-0">
                            <AvatarImage
                                v-if="barber.avatar"
                                :src="barber.avatar"
                                :alt="`Foto de ${barber.name}`"
                                class="object-cover"
                            />
                            <AvatarFallback>{{
                                getInitials(barber.name)
                            }}</AvatarFallback>
                        </Avatar>
                        <CardTitle class="min-w-0 break-words">{{
                            barber.name
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="grid grid-cols-3 gap-2 text-sm">
                            <div>
                                <p class="text-muted-foreground">Concluídos</p>
                                <Link
                                    :href="completedUrl(barber.id)"
                                    class="font-semibold underline"
                                    >{{ barber.current.completed }}</Link
                                >
                            </div>
                            <div>
                                <p class="text-muted-foreground">Agendados</p>
                                <button
                                    class="font-semibold underline"
                                    @click="showScheduled(barber)"
                                >
                                    {{ barber.current.scheduled }}
                                </button>
                            </div>
                            <div>
                                <p class="text-muted-foreground">
                                    Cancelados / faltas
                                </p>
                                <p class="font-semibold">
                                    {{ barber.current.cancelled }} /
                                    {{ barber.current.no_show }}
                                </p>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Anterior: {{ barber.previous.completed }} concluídos
                            · {{ barber.previous.scheduled }} agendados ·
                            {{ barber.previous.cancelled }} cancelados ·
                            {{ barber.previous.no_show }} faltas
                        </p>
                        <div
                            v-if="showFinancials"
                            class="border-t pt-3 text-sm"
                        >
                            <p>
                                Ticket médio:
                                {{
                                    formatMoney(
                                        barber.current.financials
                                            .average_ticket_cents,
                                    )
                                }}
                            </p>
                            <p>
                                Descontos:
                                {{
                                    formatMoney(
                                        barber.current.financials
                                            .discount_cents,
                                    )
                                }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
            <p
                v-else
                class="rounded-md border p-5 text-center text-sm text-muted-foreground"
            >
                Nenhum barbeiro cadastrado.
            </p>
        </section>
    </section>

    <Dialog :open="open" @update:open="setOpen">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader
                ><DialogTitle
                    >Agendados<template v-if="selectedBarber">
                        · {{ selectedBarber.name }}</template
                    ></DialogTitle
                ><DialogDescription
                    >Reservas no intervalo selecionado, em ordem
                    cronológica.<template v-if="showFinancials">
                        A previsão usa os preços atuais dos serviços e não
                        representa recebimento.</template
                    ></DialogDescription
                ></DialogHeader
            >
            <p v-if="loading" role="status" class="py-6 text-center">
                Carregando agendamentos…
            </p>
            <div v-else-if="error" class="space-y-3">
                <p role="alert" class="text-destructive">{{ error }}</p>
                <Button variant="outline" @click="loadScheduled(currentPage)"
                    >Tentar novamente</Button
                >
            </div>
            <template v-else-if="appointments">
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            Agendamentos no período
                        </caption>
                        <thead class="bg-muted">
                            <tr>
                                <th scope="col" class="p-3">Nº</th>
                                <th scope="col" class="p-3">Unidade</th>
                                <th scope="col" class="p-3">Barbeiro</th>
                                <th scope="col" class="p-3">Cliente</th>
                                <th scope="col" class="p-3">Horário</th>
                                <th scope="col" class="p-3">Serviços</th>
                                <th
                                    v-if="showFinancials"
                                    scope="col"
                                    class="p-3"
                                >
                                    Previsto
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="appointment in appointments.data"
                                :key="appointment.id"
                                class="border-t align-top"
                            >
                                <td class="p-3">#{{ appointment.id }}</td>
                                <td class="p-3">{{ appointment.unit }}</td>
                                <td class="p-3">
                                    {{
                                        appointment.barber ??
                                        'Barbeiro indisponível'
                                    }}
                                </td>
                                <td class="p-3">
                                    {{
                                        appointment.client?.name ??
                                        'Cliente indisponível'
                                    }}
                                    <p class="text-xs text-muted-foreground">
                                        {{ appointment.client?.phone }}
                                    </p>
                                </td>
                                <td class="p-3 whitespace-nowrap">
                                    {{ formatDateTime(appointment.start_time) }}
                                    <p>
                                        até
                                        {{
                                            formatDateTime(appointment.end_time)
                                        }}
                                    </p>
                                </td>
                                <td class="p-3">
                                    {{ appointment.services.join(', ') }}
                                </td>
                                <td
                                    v-if="showFinancials"
                                    class="p-3 whitespace-nowrap"
                                >
                                    {{
                                        appointment.expected_cents == null
                                            ? 'Previsão indisponível'
                                            : formatMoney(
                                                  appointment.expected_cents,
                                              )
                                    }}
                                </td>
                            </tr>
                            <tr v-if="!appointments.data.length">
                                <td
                                    :colspan="showFinancials ? 7 : 6"
                                    class="p-6 text-center text-muted-foreground"
                                >
                                    Nenhum atendimento agendado neste período.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 text-sm"
                >
                    <p>
                        {{
                            appointments.total
                                ? `Exibindo ${appointments.from} a ${appointments.to} de ${appointments.total}`
                                : '0 resultados'
                        }}
                    </p>
                    <nav
                        aria-label="Paginação dos agendados"
                        class="flex gap-2"
                    >
                        <Button
                            variant="outline"
                            :disabled="currentPage <= 1"
                            @click="loadScheduled(currentPage - 1)"
                            >Anterior</Button
                        ><Button
                            variant="outline"
                            :disabled="
                                (appointments.to ?? 0) >= appointments.total
                            "
                            @click="loadScheduled(currentPage + 1)"
                            >Próxima</Button
                        >
                    </nav>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>

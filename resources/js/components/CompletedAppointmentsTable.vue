<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAuth } from '@/composables/useAuth';
import { formatDateTime } from '@/lib/dateTime';
import { formatMoney as money } from '@/lib/money';
import type {
    AppointmentFilters,
    AppointmentPage,
    BarberIndicator,
    ServicesTimeline,
} from '@/types/dashboard';

const props = defineProps<{
    appointments: AppointmentPage;
    filters: AppointmentFilters;
    barbers: BarberIndicator[];
    timeline?: ServicesTimeline;
    showFinancials?: boolean;
}>();
const { isAdmin } = useAuth();

const paymentMethods: Record<string, string> = {
    credit_card: 'Cartão de crédito',
    debit_card: 'Cartão de débito',
    pix: 'Pix',
    cash: 'Dinheiro',
};
const showAmounts = computed(() => isAdmin.value && props.showFinancials);
const allColumns = [
    { key: 'id', label: 'Nº', filters: ['id'] },
    { key: 'unit', label: 'Unidade', filters: ['unit'] },
    { key: 'barber', label: 'Barbeiro', filters: ['barber'] },
    { key: 'client', label: 'Cliente', filters: ['client', 'email', 'phone'] },
    {
        key: 'period',
        label: 'Data e horário',
        filters: ['date_from', 'date_to'],
    },
    { key: 'services', label: 'Serviços', filters: ['services'] },
    { key: 'products', label: 'Produtos', filters: ['products'] },
    { key: 'services_total', label: 'Total de serviços', money: true },
    { key: 'products_total', label: 'Total de produtos', money: true },
    { key: 'discount', label: 'Desconto', money: true },
    { key: 'total', label: 'Total final', money: true },
    { key: 'payment_method', label: 'Forma de pagamento' },
    { key: 'notes', label: 'Observações', filters: ['notes'] },
].filter(
    (column) =>
        isAdmin.value ||
        ![
            'products',
            'services_total',
            'products_total',
            'discount',
            'total',
            'payment_method',
        ].includes(column.key),
);
const columns = computed(() =>
    allColumns.filter((column) => !column.money || showAmounts.value),
);
const labels: Record<string, string> = {
    id: 'Número do atendimento',
    unit: 'Unidade',
    barber: 'Barbeiro',
    client: 'Nome do cliente',
    email: 'E-mail do cliente',
    phone: 'Telefone do cliente',
    date_from: 'Data inicial',
    date_to: 'Data final',
    services: 'Buscar serviços',
    products: 'Buscar produtos ou marcas',
    notes: 'Buscar observações',
};
const moneyFields = ['services_total', 'products_total', 'discount', 'total'];
const fields = allColumns.flatMap((column) =>
    column.money
        ? [`${column.key}_min`, `${column.key}_max`]
        : (column.filters ?? [column.key]),
);
const values = reactive<Record<string, string>>({});
const perPage = ref('25');
const selectedPeriod = ref('');
const selectedBarberId = ref('');
const selectedServiceId = ref('');
const selectedChartDay = ref('');
const selectedRangeFrom = ref('');
const selectedRangeTo = ref('');
const selectedBarbershopId = ref('');
const selectedServiceName = computed(
    () =>
        props.timeline?.series.find(
            (service) => String(service.id) === selectedServiceId.value,
        )?.name ?? `#${selectedServiceId.value}`,
);
const selectedBarberName = computed(
    () =>
        props.barbers.find(
            (barber) => String(barber.id) === selectedBarberId.value,
        )?.name ?? `#${selectedBarberId.value}`,
);
const periodLabels: Record<string, string> = {
    day: 'Hoje',
    week: 'Esta semana',
    month: 'Este mês',
};
const loading = ref(false);
const page = usePage();
const errors = computed(() => page.props.errors);
const hiddenMoneyFilters = computed(
    () =>
        isAdmin.value &&
        !showAmounts.value &&
        moneyFields.some((key) =>
            ['min', 'max'].some((bound) => {
                const value = props.filters[`${key}_${bound}`];

                return value !== undefined && value !== null && value !== '';
            }),
        ),
);

watch(
    () => props.filters,
    (filters) => {
        for (const field of fields) {
            const value = filters[field];
            values[field] =
                value === null || value === undefined
                    ? ''
                    : moneyFields.some((key) => field.startsWith(`${key}_`))
                      ? (Number(value) / 100).toFixed(2)
                      : String(value);
        }

        perPage.value = String(filters.per_page ?? 25);
        selectedPeriod.value = String(filters.period ?? '');
        selectedBarberId.value = String(filters.barber_id ?? '');
        selectedServiceId.value = String(filters.service_id ?? '');
        selectedChartDay.value = String(filters.chart_day ?? '');
        selectedRangeFrom.value = String(filters.range_from ?? '');
        selectedRangeTo.value = String(filters.range_to ?? '');
        selectedBarbershopId.value = String(filters.barbershop_id ?? '');
    },
    { immediate: true },
);

function applyFilters() {
    const query: Record<string, string | number> = { per_page: perPage.value };

    if (selectedRangeFrom.value && selectedRangeTo.value) {
        query.range_from = selectedRangeFrom.value;
        query.range_to = selectedRangeTo.value;
    }

    if (selectedBarbershopId.value) {
        query.barbershop_id = selectedBarbershopId.value;
    }

    if (selectedPeriod.value) {
        query.period = selectedPeriod.value;
        query.period_at = String(props.filters.period_at);
    }

    if (selectedBarberId.value) {
        query.barber_id = selectedBarberId.value;
    }

    if (selectedServiceId.value) {
        query.service_id = selectedServiceId.value;
    }

    if (selectedChartDay.value) {
        query.chart_day = selectedChartDay.value;
    }

    for (const field of fields) {
        if (values[field] !== '') {
            query[field] = moneyFields.some((key) =>
                field.startsWith(`${key}_`),
            )
                ? Math.round(Number(values[field]) * 100)
                : values[field];
        }
    }

    router.get('/dashboard', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function clearFilters() {
    selectedPeriod.value = '';
    selectedBarberId.value = '';
    selectedServiceId.value = '';
    selectedChartDay.value = '';

    for (const field of fields) {
        values[field] = '';
    }

    applyFilters();
}

function updateColumnFilter(field: string) {
    if (field.startsWith('date_')) {
        selectedPeriod.value = '';
        selectedChartDay.value = '';
    }

    if (field === 'barber') {
        selectedBarberId.value = '';
    }

    if (field === 'services') {
        selectedServiceId.value = '';
    }
}

function pageLabel(index: number, label: string) {
    return index === 0
        ? 'Anterior'
        : index === props.appointments.links.length - 1
          ? 'Próxima'
          : label;
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="completed-appointments-title">
        <div>
            <h2 id="completed-appointments-title" class="text-xl font-semibold">
                Atendimentos realizados
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    isAdmin
                        ? 'Atendimentos concluídos de todas as unidades'
                        : 'Seus atendimentos concluídos em todas as unidades'
                }}, do mais recente para o mais antigo.
                <template v-if="showAmounts"
                    >Valores do fechamento, sem confirmação de
                    pagamento.</template
                >
            </p>
            <p
                v-if="hiddenMoneyFilters"
                role="status"
                class="rounded-md bg-muted p-3 text-sm"
            >
                Há filtros monetários ativos com valores ocultos. Ative “Exibir
                valores financeiros” para consultá-los ou use “Limpar filtros”
                para removê-los.
            </p>
        </div>
        <p
            v-if="
                selectedPeriod ||
                selectedBarberId ||
                selectedServiceId ||
                selectedChartDay
            "
            class="rounded-md bg-muted p-3 text-sm"
            role="status"
        >
            Finalizados<template v-if="selectedPeriod">
                · {{ periodLabels[selectedPeriod] }}</template
            ><template v-if="selectedBarberId">
                · Barbeiro: {{ selectedBarberName }}</template
            ><template v-if="selectedServiceId">
                · Serviço: {{ selectedServiceName }}</template
            ><template v-if="selectedChartDay">
                · Dia:
                {{ selectedChartDay.split('-').reverse().join('/') }}</template
            >. “Limpar filtros” remove os filtros adicionais da tabela.
        </p>
        <form class="space-y-4" @submit.prevent="applyFilters">
            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="loading"
                    >Aplicar filtros</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="clearFilters"
                >
                    Limpar filtros
                </Button>
                <label class="flex items-center gap-2 text-sm">
                    Por página
                    <select
                        v-model="perPage"
                        class="h-9 rounded-md border bg-background px-2"
                        @change="applyFilters"
                    >
                        <option v-for="size in [10, 25, 50, 100]" :key="size">
                            {{ size }}
                        </option>
                    </select>
                </label>
            </div>
            <ul
                v-if="Object.keys(errors).length"
                class="text-sm text-destructive"
                role="alert"
            >
                <li v-for="(error, key) in errors" :key="key">{{ error }}</li>
            </ul>
            <div
                class="overflow-x-auto rounded-lg border"
                role="region"
                aria-label="Tabela de atendimentos realizados"
                tabindex="0"
                :aria-busy="loading"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Atendimentos concluídos com filtros por coluna
                    </caption>
                    <thead class="bg-muted">
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                class="p-3 align-top font-medium"
                                :class="
                                    column.key === 'id'
                                        ? 'min-w-28'
                                        : 'min-w-52'
                                "
                            >
                                <span class="mb-2 block">{{
                                    column.label
                                }}</span>
                                <div v-if="column.money" class="space-y-2">
                                    <Input
                                        v-for="bound in ['min', 'max']"
                                        :key="bound"
                                        v-model="
                                            values[`${column.key}_${bound}`]
                                        "
                                        type="number"
                                        min="0"
                                        max="999999999.99"
                                        step="0.01"
                                        :aria-label="`${column.label}: ${bound === 'min' ? 'mínimo' : 'máximo'} em reais`"
                                        :placeholder="
                                            bound === 'min'
                                                ? 'Mínimo (R$)'
                                                : 'Máximo (R$)'
                                        "
                                    />
                                </div>
                                <select
                                    v-else-if="column.key === 'payment_method'"
                                    v-model="values.payment_method"
                                    aria-label="Filtrar forma de pagamento"
                                    class="h-9 w-full rounded-md border bg-background px-2 font-normal"
                                >
                                    <option value="">Todas</option>
                                    <option
                                        v-for="(label, value) in paymentMethods"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ label }}
                                    </option>
                                </select>
                                <div v-else class="space-y-2">
                                    <Input
                                        v-for="field in column.filters"
                                        :key="field"
                                        v-model="values[field]"
                                        :type="
                                            field.startsWith('date_')
                                                ? 'date'
                                                : field === 'id'
                                                  ? 'number'
                                                  : 'text'
                                        "
                                        :min="field === 'id' ? 1 : undefined"
                                        :aria-label="labels[field]"
                                        :placeholder="labels[field]"
                                        class="font-normal"
                                        @update:model-value="
                                            updateColumnFilter(field)
                                        "
                                    />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="appointment in appointments.data"
                            :key="appointment.id"
                            class="border-t align-top hover:bg-muted/30"
                        >
                            <td class="p-3 font-medium">
                                #{{ appointment.id }}
                            </td>
                            <td class="p-3">
                                {{ appointment.unit ?? 'Unidade indisponível' }}
                            </td>
                            <td class="p-3">
                                {{
                                    appointment.barber ??
                                    'Barbeiro indisponível'
                                }}
                            </td>
                            <td class="p-3">
                                <p class="font-medium">
                                    {{
                                        appointment.client?.name ??
                                        'Cliente indisponível'
                                    }}
                                </p>
                                <p>
                                    {{
                                        appointment.client?.email ||
                                        'E-mail não informado'
                                    }}
                                </p>
                                <p>
                                    {{
                                        appointment.client?.phone ||
                                        'Telefone não informado'
                                    }}
                                </p>
                            </td>
                            <td class="space-y-1 p-3 whitespace-nowrap">
                                <p>
                                    Início:
                                    {{ formatDateTime(appointment.start_time) }}
                                </p>
                                <p>
                                    Fim:
                                    {{ formatDateTime(appointment.end_time) }}
                                </p>
                                <p
                                    v-if="appointment.checkout"
                                    class="text-xs text-muted-foreground"
                                >
                                    Fechado em
                                    {{
                                        formatDateTime(
                                            appointment.checkout.created_at,
                                        )
                                    }}
                                </p>
                            </td>
                            <td class="space-y-2 p-3">
                                <p
                                    v-for="(
                                        service, index
                                    ) in appointment.services"
                                    :key="index"
                                >
                                    {{ service.name }} ·
                                    {{ service.duration }} min
                                    <span
                                        v-if="showAmounts"
                                        class="block text-muted-foreground"
                                        >{{ money(service.price_cents) }}</span
                                    >
                                </p>
                            </td>
                            <td v-if="isAdmin" class="space-y-2 p-3">
                                <template v-if="appointment.checkout">
                                    <p
                                        v-for="(product, index) in appointment
                                            .checkout.products"
                                        :key="index"
                                    >
                                        {{ product.name }} · {{ product.brand }}
                                        <span
                                            class="block text-muted-foreground"
                                        >
                                            {{ product.quantity }}
                                            <template v-if="showAmounts">
                                                ×
                                                {{ money(product.price_cents) }}
                                                =
                                                {{
                                                    money(
                                                        product.quantity *
                                                            product.price_cents,
                                                    )
                                                }}
                                            </template>
                                        </span>
                                    </p>
                                    <p
                                        v-if="
                                            !appointment.checkout.products
                                                .length
                                        "
                                        class="text-muted-foreground"
                                    >
                                        Sem produtos
                                    </p>
                                </template>
                                <p v-else class="text-muted-foreground">
                                    Não registrado
                                </p>
                            </td>
                            <td
                                v-if="showAmounts"
                                class="p-3 whitespace-nowrap"
                            >
                                {{
                                    money(
                                        appointment.checkout
                                            ?.services_total_cents,
                                    )
                                }}
                            </td>
                            <td
                                v-if="showAmounts"
                                class="p-3 whitespace-nowrap"
                            >
                                {{
                                    money(
                                        appointment.checkout
                                            ?.products_total_cents,
                                    )
                                }}
                            </td>
                            <td
                                v-if="showAmounts"
                                class="p-3 whitespace-nowrap"
                            >
                                {{
                                    money(appointment.checkout?.discount_cents)
                                }}
                            </td>
                            <td
                                v-if="showAmounts"
                                class="p-3 font-semibold whitespace-nowrap"
                            >
                                {{ money(appointment.checkout?.total_cents) }}
                            </td>
                            <td v-if="isAdmin" class="p-3">
                                {{
                                    appointment.checkout
                                        ? paymentMethods[
                                              appointment.checkout
                                                  .payment_method
                                          ]
                                        : 'Não registrado'
                                }}
                            </td>
                            <td
                                class="space-y-2 p-3 break-words whitespace-pre-wrap"
                            >
                                <p v-if="appointment.notes">
                                    <span class="font-medium"
                                        >Agendamento: </span
                                    >{{ appointment.notes }}
                                </p>
                                <p v-if="appointment.checkout?.notes">
                                    <span class="font-medium">Fechamento: </span
                                    >{{ appointment.checkout.notes }}
                                </p>
                                <span
                                    v-if="
                                        !appointment.notes &&
                                        !appointment.checkout?.notes
                                    "
                                    class="text-muted-foreground"
                                    >Sem observações</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!appointments.data.length">
                            <td
                                :colspan="columns.length"
                                class="p-6 text-center text-muted-foreground"
                            >
                                Nenhum atendimento realizado encontrado para os
                                filtros informados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </form>
        <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
            <p v-if="appointments.total">
                Exibindo {{ appointments.from }} a {{ appointments.to }} de
                {{ appointments.total }} atendimentos
            </p>
            <p v-else>0 resultados</p>
            <nav
                aria-label="Paginação dos atendimentos"
                class="flex flex-wrap gap-1"
            >
                <template
                    v-for="(link, index) in appointments.links"
                    :key="index"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        preserve-state
                        :aria-current="link.active ? 'page' : undefined"
                        class="rounded border px-3 py-1"
                        :class="
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted'
                        "
                        >{{ pageLabel(index, link.label) }}</Link
                    >
                    <span
                        v-else
                        class="rounded border px-3 py-1 text-muted-foreground"
                        aria-disabled="true"
                        >{{ pageLabel(index, link.label) }}</span
                    >
                </template>
            </nav>
        </div>
    </section>
</template>

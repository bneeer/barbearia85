<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CompletedAppointmentsTable from '@/components/CompletedAppointmentsTable.vue';
import DashboardIndicators from '@/components/DashboardIndicators.vue';
import ServicesTimelineChart from '@/components/ServicesTimelineChart.vue';
import Switch from '@/components/ui/switch/Switch.vue';
import { useAuth } from '@/composables/useAuth';
import { dashboard } from '@/routes';
import type {
    AppointmentFilters,
    AppointmentPage,
    DashboardIndicators as Indicators,
    DashboardSummary,
    ServicesTimeline,
} from '@/types/dashboard';

type ShortcutRange = { from: string; to: string };

const props = defineProps<{
    appointments?: AppointmentPage;
    filters?: AppointmentFilters;
    indicators?: Indicators;
    summary?: DashboardSummary;
    servicesTimeline?: ServicesTimeline;
    rangeTimeline?: ServicesTimeline;
    barbershops?: { id: number; name: string }[];
    shortcuts?: Record<'day' | 'week' | 'month', ShortcutRange>;
}>();
const { isAdmin, isBarber } = useAuth();
const showFinancials = ref(false);
const rangeFrom = ref('');
const rangeTo = ref('');
const barbershopId = ref('');
const rangeError = ref('');
const appliedBarbershopId = computed(() =>
    String(props.filters?.barbershop_id ?? ''),
);

watch(
    () => [
        props.filters?.range_from,
        props.filters?.range_to,
        props.filters?.barbershop_id,
    ],
    ([from, to, shopId]) => {
        rangeFrom.value = String(from ?? '');
        rangeTo.value = String(to ?? '');
        barbershopId.value = String(shopId ?? '');
    },
    { immediate: true },
);

function currentQuery(): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(props.filters ?? {})) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    delete query.page;
    delete query.period;
    delete query.period_at;

    return query;
}

function applyRange(from = rangeFrom.value, to = rangeTo.value) {
    if (!from || !to) {
        rangeError.value = 'Informe as duas datas do período.';

        return;
    }

    if (from > to) {
        rangeError.value =
            'A data final deve ser igual ou posterior à inicial.';

        return;
    }

    rangeError.value = '';
    const query = currentQuery();
    query.range_from = from;
    query.range_to = to;

    if (barbershopId.value) {
        query.barbershop_id = barbershopId.value;
    } else {
        delete query.barbershop_id;
    }

    router.get('/dashboard', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function setShortcut(key: 'day' | 'week' | 'month') {
    const shortcut = props.shortcuts?.[key];

    if (!shortcut) {
        return;
    }

    rangeFrom.value = shortcut.from;
    rangeTo.value = shortcut.to;
    applyRange(shortcut.from, shortcut.to);
}

const exportUrl = computed(() => {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(props.filters ?? {})) {
        if (
            key !== 'page' &&
            key !== 'per_page' &&
            value !== null &&
            value !== ''
        ) {
            query.set(key, String(value));
        }
    }

    return `/dashboard/export?${query}`;
});

defineOptions({
    layout: { breadcrumbs: [{ title: 'Início', href: dashboard() }] },
});
</script>

<template>
    <Head title="Início" />
    <div class="min-w-0 space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold">
                {{
                    isAdmin
                        ? 'Painel de Controle'
                        : isBarber
                          ? 'Meus atendimentos'
                          : 'Bem-vindo à Barbearia 85'
                }}
            </h1>
            <div v-if="isAdmin" class="flex items-center gap-2">
                <Switch id="show-financials" v-model="showFinancials" />
                <label for="show-financials" class="text-sm font-medium"
                    >Exibir valores financeiros</label
                >
            </div>
        </div>

        <section
            v-if="isBarber && summary"
            class="space-y-4 rounded-lg border p-4"
            aria-label="Filtros do dashboard"
        >
            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.2fr_auto]"
            >
                <label class="grid gap-1 text-sm font-medium">
                    Data inicial
                    <input
                        v-model="rangeFrom"
                        type="date"
                        required
                        class="h-10 min-w-0 rounded-md border bg-background px-3 font-normal"
                    />
                </label>
                <label class="grid gap-1 text-sm font-medium">
                    Data final
                    <input
                        v-model="rangeTo"
                        type="date"
                        required
                        class="h-10 min-w-0 rounded-md border bg-background px-3 font-normal"
                    />
                </label>
                <label class="grid gap-1 text-sm font-medium">
                    Unidade
                    <select
                        v-model="barbershopId"
                        class="h-10 min-w-0 rounded-md border bg-background px-3 font-normal"
                    >
                        <option value="">Todas as unidades</option>
                        <option
                            v-for="shop in barbershops"
                            :key="shop.id"
                            :value="String(shop.id)"
                        >
                            {{ shop.name }}
                        </option>
                    </select>
                </label>
                <button
                    type="button"
                    class="h-10 self-end rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring"
                    @click="applyRange()"
                >
                    Aplicar
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="mr-1 text-sm text-muted-foreground">Atalhos:</span>
                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                    @click="setShortcut('day')"
                >
                    Hoje
                </button>
                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                    @click="setShortcut('week')"
                >
                    Esta semana
                </button>
                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                    @click="setShortcut('month')"
                >
                    Este mês
                </button>
                <a
                    :href="exportUrl"
                    class="ml-auto rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    >Exportar XLSX</a
                >
            </div>
            <p v-if="rangeError" role="alert" class="text-sm text-destructive">
                {{ rangeError }}
            </p>
            <p class="text-xs text-muted-foreground">
                Intervalo máximo: 365 dias. A comparação usa o intervalo
                anterior de mesma duração.
            </p>
        </section>

        <DashboardIndicators
            v-if="isBarber && summary"
            :summary="summary"
            :barbershop-id="appliedBarbershopId"
            :show-financials="isAdmin && showFinancials"
        />
        <ServicesTimelineChart
            v-if="isBarber && rangeTimeline"
            :timeline="rangeTimeline"
            :filters="filters ?? {}"
        />
        <CompletedAppointmentsTable
            v-if="isBarber && appointments && indicators"
            :appointments="appointments"
            :filters="filters ?? {}"
            :barbers="indicators.barbers"
            :timeline="rangeTimeline"
            :show-financials="isAdmin && showFinancials"
        />
        <p v-if="!isBarber" class="mt-2 text-muted-foreground">
            Gerencie sua barbearia pelo menu ao lado.
        </p>
    </div>
</template>

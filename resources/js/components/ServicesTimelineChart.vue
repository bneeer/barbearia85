<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
    Legend,
} from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/composables/useAuth';
import type { AppointmentFilters, ServicesTimeline } from '@/types/dashboard';

ChartJS.register(
    CategoryScale,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
    Legend,
);
const props = defineProps<{
    timeline: ServicesTimeline;
    filters: AppointmentFilters;
}>();
const { isAdmin } = useAuth();
const visibleSeries = computed(() =>
    props.timeline.series.filter(
        (service) =>
            service.counts.some((count) => count > 0) ||
            service.scheduled_counts.some((count) => count > 0),
    ),
);
const chartSeries = computed(() =>
    visibleSeries.value.flatMap((service) => [
        { service, status: 'completed' as const, values: service.counts },
        {
            service,
            status: 'scheduled' as const,
            values: service.scheduled_counts,
        },
    ]),
);
const colors = [
    '#2563eb',
    '#dc2626',
    '#059669',
    '#9333ea',
    '#d97706',
    '#0891b2',
    '#db2777',
    '#4f46e5',
];
const data = computed<ChartData<'line'>>(() => ({
    labels: props.timeline.dates.map(formatDate),
    datasets: chartSeries.value.map((item, index) => {
        const serviceIndex = Math.floor(index / 2);
        const color = colors[serviceIndex % colors.length];

        return {
            label: `${item.service.name} (#${item.service.id}) · ${item.status === 'completed' ? 'concluídos' : 'agendados'}`,
            data: item.values,
            borderColor: color,
            backgroundColor: color,
            borderDash: item.status === 'scheduled' ? [6, 4] : [],
            borderWidth: 2,
            pointRadius: 2,
            pointHitRadius: 8,
            tension: 0,
        };
    }),
}));
const options: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    locale: 'pt-BR',
    interaction: { mode: 'nearest', intersect: true },
    plugins: {
        legend: { position: 'bottom' },
        tooltip: {
            callbacks: {
                label: (context) =>
                    `${context.dataset.label}: ${context.parsed.y} serviço(s)`,
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Quantidade de serviços' },
        },
        x: {
            ticks: { maxTicksLimit: 12 },
            title: { display: true, text: 'Data do atendimento' },
        },
    },
    onClick: (_event, elements) => {
        const point = elements[0];
        const item = point ? chartSeries.value[point.datasetIndex] : null;

        if (point && item?.status === 'completed') {
            filterTable(props.timeline.dates[point.index], item.service.id);
        }
    },
};

function formatDate(date: string) {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

function filterTable(date: string, serviceId: number) {
    const query: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(props.filters)) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    delete query.page;
    query.chart_day = date;
    query.service_id = serviceId;
    router.get('/dashboard', query, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Serviços no período</CardTitle>
            <p class="text-sm text-muted-foreground">
                {{
                    isAdmin
                        ? 'Todas as unidades selecionadas.'
                        : 'Somente seus atendimentos.'
                }}
                Cada serviço do atendimento conta uma vez. Linhas contínuas
                representam concluídos; linhas tracejadas, agendados. Ambos
                respeitam o intervalo e a unidade escolhidos.
            </p>
        </CardHeader>
        <CardContent class="space-y-4">
            <p class="text-xs text-muted-foreground">
                Clique em um ponto contínuo para filtrar os atendimentos
                concluídos por data e serviço. A tabela abaixo também permite
                consultar os dados sem usar o gráfico.
            </p>
            <div v-if="visibleSeries.length" class="relative h-96">
                <Line
                    :data="data"
                    :options="options"
                    role="img"
                    aria-label="Serviços concluídos e agendados no período; dados também disponíveis na tabela abaixo"
                />
            </div>
            <p
                v-else
                class="rounded-md border p-6 text-center text-sm text-muted-foreground"
            >
                Nenhum serviço concluído ou agendado neste período.
            </p>
            <details
                v-if="visibleSeries.length"
                class="rounded-md border p-3 text-sm"
            >
                <summary class="cursor-pointer font-medium">
                    Consultar dados e filtrar a tabela
                </summary>
                <div class="mt-3 max-h-80 overflow-auto">
                    <table class="w-full text-right tabular-nums">
                        <caption class="sr-only">
                            Serviços por data e situação
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" class="p-2 text-left">Data</th>
                                <th
                                    v-for="service in visibleSeries"
                                    :key="service.id"
                                    scope="col"
                                    class="p-2"
                                >
                                    {{ service.name }} (#{{ service.id }})
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(date, index) in timeline.dates"
                                :key="date"
                                class="border-t"
                            >
                                <th
                                    scope="row"
                                    class="p-2 text-left whitespace-nowrap"
                                >
                                    {{ formatDate(date) }}
                                </th>
                                <td
                                    v-for="service in visibleSeries"
                                    :key="service.id"
                                    class="p-2"
                                >
                                    <button
                                        v-if="service.counts[index] > 0"
                                        type="button"
                                        class="rounded px-2 py-1 underline hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                        :aria-label="`${service.name}, ${formatDate(date)}: consultar ${service.counts[index]} concluídos`"
                                        @click="filterTable(date, service.id)"
                                    >
                                        {{ service.counts[index] }}
                                    </button>
                                    <span v-else>{{
                                        service.counts[index]
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        /
                                        {{
                                            service.scheduled_counts[index]
                                        }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Em cada célula: concluídos / agendados.
                    </p>
                </div>
            </details>
        </CardContent>
    </Card>
</template>

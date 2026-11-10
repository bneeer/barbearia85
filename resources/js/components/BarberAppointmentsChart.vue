<script setup lang="ts">
import {
    Chart as ChartJS,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { DashboardSummary } from '@/types/dashboard';

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend);

const props = defineProps<{
    barbers: DashboardSummary['barbers'];
}>();
const activeBarbers = computed(() =>
    props.barbers
        .filter(
            (barber) => barber.current.completed + barber.current.scheduled > 0,
        )
        .toSorted(
            (a, b) =>
                b.current.completed +
                    b.current.scheduled -
                    (a.current.completed + a.current.scheduled) ||
                a.name.localeCompare(b.name, 'pt-BR') ||
                a.id - b.id,
        ),
);
const data = computed<ChartData<'bar'>>(() => ({
    labels: activeBarbers.value.map(
        (barber) => `${barber.name} (#${barber.id})`,
    ),
    datasets: [
        {
            label: 'Concluídos',
            data: activeBarbers.value.map((barber) => barber.current.completed),
            backgroundColor: '#2563eb',
        },
        {
            label: 'Agendados',
            data: activeBarbers.value.map((barber) => barber.current.scheduled),
            backgroundColor: '#d97706',
        },
    ],
}));
const options: ChartOptions<'bar'> = {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    locale: 'pt-BR',
    plugins: {
        legend: { position: 'bottom' },
        tooltip: {
            callbacks: {
                label: (context) =>
                    `${context.dataset.label}: ${context.parsed.x} atendimento(s)`,
            },
        },
    },
    scales: {
        x: {
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Quantidade de atendimentos' },
        },
        y: { ticks: { autoSkip: false } },
    },
};
</script>

<template>
    <Card class="min-w-0">
        <CardHeader>
            <CardTitle>Atendimentos por barbeiro</CardTitle>
            <p class="text-sm text-muted-foreground">
                Concluídos e agendados no período e na unidade selecionados,
                ordenados pelo total. Cancelamentos e faltas não entram nas
                barras.
            </p>
        </CardHeader>
        <CardContent class="space-y-4">
            <template v-if="activeBarbers.length">
                <div class="max-h-[36rem] overflow-y-auto">
                    <div
                        class="relative"
                        :style="{
                            height: `${Math.max(280, activeBarbers.length * 64 + 80)}px`,
                        }"
                    >
                        <Bar
                            :data="data"
                            :options="options"
                            role="img"
                            aria-label="Barras horizontais de atendimentos concluídos e agendados por barbeiro. Valores disponíveis na tabela abaixo."
                        />
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            Atendimentos por barbeiro no período selecionado
                        </caption>
                        <thead>
                            <tr class="border-b text-muted-foreground">
                                <th scope="col" class="p-2">Barbeiro</th>
                                <th scope="col" class="p-2">Concluídos</th>
                                <th scope="col" class="p-2">Agendados</th>
                                <th scope="col" class="p-2">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="barber in activeBarbers"
                                :key="barber.id"
                                class="border-b"
                            >
                                <th scope="row" class="p-2 font-medium">
                                    {{ barber.name }} (#{{ barber.id }})
                                </th>
                                <td class="p-2">
                                    {{ barber.current.completed }}
                                </td>
                                <td class="p-2">
                                    {{ barber.current.scheduled }}
                                </td>
                                <td class="p-2">
                                    {{
                                        barber.current.completed +
                                        barber.current.scheduled
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                Nenhum atendimento concluído ou agendado no período selecionado.
            </p>
        </CardContent>
    </Card>
</template>

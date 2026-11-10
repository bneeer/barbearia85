<script setup lang="ts">
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatMoney } from '@/lib/money';
import type { DashboardFinancialSummary } from '@/types/dashboard';

ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps<{
    paymentMethods: DashboardFinancialSummary['payment_methods'];
}>();
const labels: Record<string, string> = {
    credit_card: 'Cartão de crédito',
    debit_card: 'Cartão de débito',
    pix: 'Pix',
    cash: 'Dinheiro',
};
const colors: Record<string, string> = {
    credit_card: '#2563eb',
    debit_card: '#9333ea',
    pix: '#059669',
    cash: '#d97706',
};
const methods = computed(() =>
    Object.entries(props.paymentMethods)
        .map(([key, method]) => ({
            key,
            label: labels[key] ?? key,
            cents: method.total_cents,
            color: colors[key] ?? '#64748b',
        }))
        .sort((a, b) => b.cents - a.cents || a.key.localeCompare(b.key)),
);
const totalCents = computed(() =>
    methods.value.reduce((sum, method) => sum + method.cents, 0),
);
const percentage = new Intl.NumberFormat('pt-BR', {
    style: 'percent',
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

function share(cents: number) {
    return totalCents.value > 0
        ? percentage.format(cents / totalCents.value)
        : 'Não se aplica';
}

const data = computed<ChartData<'doughnut'>>(() => ({
    labels: methods.value.map((method) => method.label),
    datasets: [
        {
            data: methods.value.map((method) => method.cents),
            backgroundColor: methods.value.map((method) => method.color),
            borderWidth: 2,
        },
    ],
}));
const options: ChartOptions<'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    locale: 'pt-BR',
    cutout: '65%',
    plugins: {
        legend: { position: 'bottom' },
        tooltip: {
            callbacks: {
                label: (context) =>
                    `${context.label}: ${formatMoney(context.parsed)} (${share(context.parsed)})`,
            },
        },
    },
};
</script>

<template>
    <Card class="min-w-0">
        <CardHeader>
            <CardTitle>Faturamento por forma de pagamento</CardTitle>
            <p class="text-sm text-muted-foreground">
                Participação no valor total dos fechamentos de atendimentos
                concluídos no período e na unidade selecionados, incluindo
                produtos e descontando os descontos registrados.
            </p>
        </CardHeader>
        <CardContent class="space-y-4">
            <p class="text-lg font-semibold">
                Faturamento total: {{ formatMoney(totalCents) }}
            </p>
            <div v-if="totalCents > 0" class="relative h-80">
                <Doughnut
                    :data="data"
                    :options="options"
                    role="img"
                    aria-label="Gráfico de rosca do faturamento por forma de pagamento. Valores absolutos e percentuais disponíveis na tabela abaixo."
                />
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Nenhum faturamento registrado no período selecionado. A
                participação percentual não se aplica quando o total é zero.
            </p>
            <div v-if="methods.length" class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Valores e participação de cada forma de pagamento no
                        faturamento total
                    </caption>
                    <thead>
                        <tr class="border-b text-muted-foreground">
                            <th scope="col" class="p-2">Forma de pagamento</th>
                            <th scope="col" class="p-2">Faturamento</th>
                            <th scope="col" class="p-2">Participação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="method in methods"
                            :key="method.key"
                            class="border-b"
                        >
                            <th scope="row" class="p-2 font-medium">
                                {{ method.label }}
                            </th>
                            <td class="p-2 tabular-nums">
                                {{ formatMoney(method.cents) }}
                            </td>
                            <td class="p-2 tabular-nums">
                                {{ share(method.cents) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <th scope="row" class="p-2">Total</th>
                            <td class="p-2 tabular-nums">
                                {{ formatMoney(totalCents) }}
                            </td>
                            <td class="p-2 tabular-nums">
                                {{ share(totalCents) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">
                Percentuais calculados sobre o faturamento total, mesmo ao
                ocultar uma fatia pela legenda; podem apresentar diferenças de
                arredondamento. Valores de fechamento administrativo, sem
                confirmação de recebimento.
            </p>
        </CardContent>
    </Card>
</template>

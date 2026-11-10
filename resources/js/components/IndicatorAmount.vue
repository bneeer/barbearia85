<script setup lang="ts">
import { formatMoney } from '@/lib/money';
import type { PeriodIndicator } from '@/types/dashboard';

defineProps<{
    financials: NonNullable<PeriodIndicator['financials']>;
    kind: 'completed' | 'scheduled' | 'total';
}>();

const labels = {
    completed: 'Fechamento',
    scheduled: 'Previsto',
    total: 'Combinado',
};
</script>

<template>
    <span
        class="inline-flex flex-col text-sm font-normal text-muted-foreground"
    >
        <span
            >({{ labels[kind] }}:{{
                formatMoney(financials[`${kind}_cents`])
            }})</span
        >
        <span
            v-if="kind !== 'scheduled' && financials.missing_completed"
            class="text-xs"
        >
            Parcial: {{ financials.missing_completed }} sem fechamento
        </span>
        <span
            v-if="kind !== 'completed' && financials.missing_scheduled"
            class="text-xs"
        >
            Parcial: {{ financials.missing_scheduled }} sem previsão
        </span>
    </span>
</template>

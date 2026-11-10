<script setup lang="ts">
import { formatDashboardComparison } from '@/lib/dashboardComparison';
import type { ComparisonMode } from '@/lib/dashboardComparison';

defineProps<{
    current: number | null | undefined;
    previous: number | null | undefined;
    mode?: ComparisonMode;
    label?: string;
}>();
</script>

<template>
    <span class="block text-xs text-muted-foreground">
        {{ label ? `${label}: ` : 'Variação: ' }}
        {{ formatDashboardComparison(current, previous, mode) }}
    </span>
</template>

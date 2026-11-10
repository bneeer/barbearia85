<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/composables/useAuth';
import { formatMoney } from '@/lib/money';
import type { ServicesTimeline } from '@/types/dashboard';

const props = defineProps<{
    timeline: ServicesTimeline;
    showFinancials: boolean;
}>();
const { isAdmin } = useAuth();
const completedServices = computed(() =>
    props.timeline.series
        .map((service) => ({
            ...service,
            count: service.counts.reduce((total, count) => total + count, 0),
        }))
        .filter((service) => service.count > 0),
);
const hasMissingRevenue = computed(() =>
    completedServices.value.some((service) => service.revenue_cents === null),
);
const highlights = computed(() => {
    const quantities = completedServices.value.map((service) => ({
        id: service.id,
        name: service.name,
        value: service.count,
        label: `${service.count} serviço(s) prestado(s)`,
    }));
    const revenues = completedServices.value.flatMap((service) =>
        typeof service.revenue_cents === 'number'
            ? [
                  {
                      id: service.id,
                      name: service.name,
                      value: service.revenue_cents,
                      label: formatMoney(service.revenue_cents),
                  },
              ]
            : [],
    );
    const groups = [
        { title: 'Mais prestados', items: quantities, highest: true },
        { title: 'Menos prestados', items: quantities, highest: false },
        ...(isAdmin.value && props.showFinancials
            ? [
                  {
                      title: 'Maior faturamento',
                      items: revenues,
                      highest: true,
                  },
                  {
                      title: 'Menor faturamento',
                      items: revenues,
                      highest: false,
                  },
              ]
            : []),
    ];

    return groups.map((group) => {
        const values = group.items.map((item) => item.value);
        const extreme = group.highest
            ? Math.max(...values)
            : Math.min(...values);

        return {
            title: group.title,
            items: group.items.filter((item) => item.value === extreme),
        };
    });
});
</script>

<template>
    <section class="space-y-4" aria-labelledby="service-highlights-title">
        <div>
            <h2 id="service-highlights-title" class="text-xl font-semibold">
                Destaques dos serviços
            </h2>
            <p class="text-sm text-muted-foreground">
                Somente serviços prestados em atendimentos concluídos no período
                e na unidade selecionados. Empates são exibidos juntos.
            </p>
        </div>
        <div
            class="grid gap-4 sm:grid-cols-2"
            :class="{ 'xl:grid-cols-4': isAdmin && showFinancials }"
        >
            <Card v-for="highlight in highlights" :key="highlight.title">
                <CardHeader>
                    <CardTitle>{{ highlight.title }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul v-if="highlight.items.length" class="space-y-3">
                        <li v-for="item in highlight.items" :key="item.id">
                            <p class="font-medium">{{ item.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ item.label }}
                            </p>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            completedServices.length
                                ? 'Faturamento indisponível: serviços sem fechamento registrado.'
                                : 'Nenhum serviço prestado no período.'
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>
        <p
            v-if="isAdmin && showFinancials"
            class="text-xs text-muted-foreground"
        >
            Faturamento total dos serviços nos fechamentos, antes do desconto
            geral e sem produtos; não representa lucro líquido.
            <span v-if="hasMissingRevenue">
                Serviços com algum atendimento sem fechamento são excluídos do
                ranking financeiro, pois seu faturamento está incompleto.
            </span>
        </p>
    </section>
</template>

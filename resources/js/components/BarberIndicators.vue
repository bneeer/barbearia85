<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import IndicatorAmount from '@/components/IndicatorAmount.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import type { BarberIndicator, DashboardPeriod } from '@/types/dashboard';

defineProps<{
    barbers: BarberIndicator[];
    referenceAt: string;
    showFinancials?: boolean;
}>();
defineEmits<{
    scheduled: [
        period: DashboardPeriod,
        reference: string,
        barber: BarberIndicator,
    ];
}>();
const { getInitials } = useInitials();
const { isAdmin } = useAuth();
const labels: Record<DashboardPeriod, string> = {
    day: 'Hoje',
    week: 'Esta semana',
    month: 'Este mês',
};

function completedUrl(
    period: DashboardPeriod,
    referenceAt: string,
    barberId: number,
) {
    return `/dashboard?${new URLSearchParams({
        period,
        period_at: referenceAt,
        barber_id: String(barberId),
    })}`;
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="barber-indicators-title">
        <div>
            <h2 id="barber-indicators-title" class="text-xl font-semibold">
                {{ isAdmin ? 'Atendimentos por barbeiro' : 'Meus indicadores' }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    isAdmin ? 'Todas as unidades' : 'Somente seus atendimentos'
                }}, com os mesmos períodos e critérios dos indicadores gerais,
                independentemente dos filtros da tabela. Clique em Concluídos
                para filtrar a tabela ou em Agendados para uma consulta rápida.
            </p>
        </div>
        <div
            v-if="barbers.length"
            :class="`${showFinancials ? 'md:grid-cols-1 xl:grid-cols-2' : 'md:grid-cols-2 xl:grid-cols-3'} grid gap-4`"
        >
            <Card v-for="barber in barbers" :key="barber.id" class="min-w-0">
                <CardHeader class="flex flex-row items-center gap-3">
                    <Avatar class="size-14 shrink-0">
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
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-right text-sm tabular-nums">
                        <caption class="sr-only">
                            Atendimentos de
                            {{
                                barber.name
                            }}
                            por período
                        </caption>
                        <thead class="text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="pb-3 text-left">
                                    Período
                                </th>
                                <th scope="col" class="pb-3 pl-2">Total</th>
                                <th scope="col" class="pb-3 pl-2">
                                    Concluídos
                                </th>
                                <th scope="col" class="pb-3 pl-2">Agendados</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="indicator in barber.periods"
                                :key="indicator.period"
                                class="border-t"
                            >
                                <th
                                    scope="row"
                                    class="py-3 text-left font-medium"
                                >
                                    {{ labels[indicator.period] }}
                                </th>
                                <td class="py-3 pl-2 font-semibold">
                                    {{ indicator.total }}
                                    <IndicatorAmount
                                        v-if="
                                            isAdmin &&
                                            showFinancials &&
                                            indicator.financials
                                        "
                                        :financials="indicator.financials"
                                        kind="total"
                                    />
                                </td>
                                <td class="py-3 pl-2 text-primary">
                                    <Link
                                        :href="
                                            completedUrl(
                                                indicator.period,
                                                referenceAt,
                                                barber.id,
                                            )
                                        "
                                        preserve-scroll
                                        preserve-state
                                        class="inline-flex min-h-9 min-w-9 flex-col items-center justify-center rounded-md px-2 underline underline-offset-4 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                        :aria-label="
                                            showFinancials
                                                ? undefined
                                                : `${barber.name}, ${labels[indicator.period]}: ver ${indicator.completed} concluídos na tabela`
                                        "
                                        >{{ indicator.completed }}
                                        <IndicatorAmount
                                            v-if="
                                                isAdmin &&
                                                showFinancials &&
                                                indicator.financials
                                            "
                                            :financials="indicator.financials"
                                            kind="completed"
                                        />
                                    </Link>
                                </td>
                                <td class="py-3 pl-2">
                                    <button
                                        type="button"
                                        class="inline-flex min-h-9 min-w-9 flex-col items-center justify-center rounded-md px-2 underline underline-offset-4 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                        :aria-label="
                                            showFinancials
                                                ? undefined
                                                : `${barber.name}, ${labels[indicator.period]}: consultar ${indicator.scheduled} agendados`
                                        "
                                        @click="
                                            $emit(
                                                'scheduled',
                                                indicator.period,
                                                referenceAt,
                                                barber,
                                            )
                                        "
                                    >
                                        {{ indicator.scheduled }}
                                        <IndicatorAmount
                                            v-if="
                                                isAdmin &&
                                                showFinancials &&
                                                indicator.financials
                                            "
                                            :financials="indicator.financials"
                                            kind="scheduled"
                                        />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
        <p
            v-else
            class="rounded-lg border p-6 text-center text-sm text-muted-foreground"
        >
            Nenhum barbeiro cadastrado.
        </p>
    </section>
</template>

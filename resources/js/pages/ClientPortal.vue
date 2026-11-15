<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PrivacyPagination from '@/components/PrivacyPagination.vue';
import PublicFooter from '@/components/PublicFooter.vue';
import type { PageLink } from '@/types/privacy';

type Appointment = {
    id: number;
    service: string;
    barbershop: string;
    barber: string;
    starts_at: string;
    timezone: string;
    status: string;
};
type LoyaltyProgram = {
    barbershopId: number;
    barbershop: string;
    enabled: boolean;
    goal: number;
    reward: string | null;
    stampsBalance: number;
    stampsProgress: number;
    rewardsAvailable: number;
};

defineProps<{
    valid: boolean;
    dashboard: {
        totalCompleted: number;
        favoriteService: string | null;
        favoriteBarber: string | null;
        loyalty: LoyaltyProgram[];
        upcoming: Appointment[];
        history: { data: Appointment[]; links: PageLink[] };
    } | null;
}>();

const statuses: Record<string, string> = {
    scheduled: 'Agendado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
    no_show: 'Não compareceu',
};
</script>

<template>
    <Head title="Meus agendamentos">
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
    </Head>
    <div class="flex min-h-dvh flex-col bg-background text-foreground">
        <main class="mx-auto w-full max-w-4xl flex-1 space-y-8 px-5 py-10">
            <Link href="/" class="text-sm underline underline-offset-4"
                >Voltar ao início</Link
            >
            <h1 class="text-3xl font-bold">Meus agendamentos</h1>
            <p v-if="!valid || !dashboard" role="status">
                Link inválido ou expirado. Volte ao início para solicitar um
                novo acesso.
            </p>
            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Informações associadas ao seu e-mail em todas as unidades.
                    Os indicadores consideram apenas atendimentos concluídos.
                </p>
                <section
                    aria-label="Indicadores"
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <div class="rounded-xl border p-5">
                        <p class="text-sm text-muted-foreground">
                            Atendimentos concluídos
                        </p>
                        <p class="mt-2 text-xl font-semibold">
                            {{ dashboard.totalCompleted }}
                        </p>
                    </div>
                    <div class="rounded-xl border p-5">
                        <p class="text-sm text-muted-foreground">
                            Serviço mais consumido
                        </p>
                        <p class="mt-2 text-xl font-semibold">
                            {{
                                dashboard.favoriteService ??
                                'Ainda não disponível'
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl border p-5">
                        <p class="text-sm text-muted-foreground">
                            Barbeiro favorito
                        </p>
                        <p class="mt-2 text-xl font-semibold">
                            {{
                                dashboard.favoriteBarber ??
                                'Ainda não disponível'
                            }}
                        </p>
                    </div>
                </section>
                <section class="space-y-4">
                    <h2 class="text-xl font-semibold">
                        Fidelidade por unidade
                    </h2>
                    <p v-if="!dashboard.loyalty.length">
                        Nenhum programa de fidelidade disponível para seu
                        cadastro.
                    </p>
                    <article
                        v-for="program in dashboard.loyalty"
                        :key="program.barbershopId"
                        class="space-y-2 rounded-xl border p-4"
                    >
                        <h3 class="font-semibold">{{ program.barbershop }}</h3>
                        <p>
                            {{
                                program.enabled
                                    ? 'Programa ativo'
                                    : 'Programa pausado'
                            }}
                            · {{ program.stampsBalance }} carimbos acumulados
                        </p>
                        <p v-if="program.reward">
                            Recompensa: {{ program.reward }}
                        </p>
                        <template v-if="program.enabled">
                            <p>
                                Progresso: {{ program.stampsProgress }} de
                                {{ program.goal }} carimbos para a próxima
                                recompensa.
                            </p>
                            <p v-if="program.rewardsAvailable">
                                Recompensas disponíveis para resgate:
                                {{ program.rewardsAvailable }}
                            </p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            Não são concedidos novos carimbos enquanto o
                            programa estiver pausado.
                        </p>
                    </article>
                </section>
                <section class="space-y-4">
                    <h2 class="text-xl font-semibold">Próximos agendamentos</h2>
                    <p v-if="!dashboard.upcoming.length">
                        Você não possui próximos agendamentos.
                    </p>
                    <article
                        v-for="item in dashboard.upcoming"
                        :key="item.id"
                        class="space-y-1 rounded-xl border p-4"
                    >
                        <h3 class="font-semibold">{{ item.service }}</h3>
                        <p>{{ item.barbershop }} · {{ item.barber }}</p>
                        <p>{{ item.starts_at }} ({{ item.timezone }})</p>
                    </article>
                </section>
                <section class="space-y-4">
                    <h2 class="text-xl font-semibold">
                        Histórico de agendamentos
                    </h2>
                    <p v-if="!dashboard.history.data.length">
                        Nenhum agendamento encontrado.
                    </p>
                    <article
                        v-for="item in dashboard.history.data"
                        :key="item.id"
                        class="space-y-1 rounded-xl border p-4"
                    >
                        <h3 class="font-semibold">{{ item.service }}</h3>
                        <p>{{ item.barbershop }} · {{ item.barber }}</p>
                        <p>{{ item.starts_at }} ({{ item.timezone }})</p>
                        <p class="text-sm text-muted-foreground">
                            {{ statuses[item.status] ?? item.status }}
                        </p>
                    </article>
                    <PrivacyPagination
                        v-if="dashboard.history.links.length > 3"
                        :links="dashboard.history.links"
                    />
                </section>
            </template>
        </main>
        <PublicFooter />
    </div>
</template>

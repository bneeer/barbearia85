<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PublicFooter from '@/components/PublicFooter.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';

defineProps<{
    canCancel: boolean;
    message: string | null;
    confirmUrl: string | null;
    appointments: {
        id: number;
        service: string;
        barbershop: string;
        barber: string;
        starts_at: string;
        deadline: string;
        timezone: string;
        phones: string[];
        reason: string | null;
    }[];
}>();
const form = useForm<{ cancellation?: string }>({});
</script>

<template>
    <Toaster />
    <Head title="Confirmar cancelamento">
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
    </Head>
    <div class="flex min-h-dvh flex-col bg-background text-foreground">
        <main class="flex-1 px-5 py-12">
            <div class="mx-auto max-w-xl space-y-6">
                <h1 class="text-2xl font-bold">Cancelamento de agendamentos</h1>
                <p v-if="message" role="status">{{ message }}</p>
                <p v-if="canCancel">
                    Deseja cancelar
                    {{
                        appointments.length > 1
                            ? 'todos os agendamentos abaixo'
                            : 'este agendamento'
                    }}? Os horários deixarão de estar reservados.
                </p>
                <article
                    v-for="appointment in appointments"
                    :key="appointment.id"
                    class="space-y-2 rounded-xl border p-5"
                >
                    <h2 class="text-lg font-semibold">
                        {{ appointment.service }}
                    </h2>
                    <p>
                        {{ appointment.barbershop }} · {{ appointment.barber }}
                    </p>
                    <p>
                        {{ appointment.starts_at }} ({{ appointment.timezone }})
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Prazo para cancelar: {{ appointment.deadline }} ({{
                            appointment.timezone
                        }})
                    </p>
                    <p v-if="appointment.reason">{{ appointment.reason }}</p>
                    <p v-if="appointment.phones.length" class="text-sm">
                        Contato: {{ appointment.phones.join(' · ') }}
                    </p>
                </article>
                <InputError :message="form.errors.cancellation" />
                <form
                    v-if="canCancel && confirmUrl"
                    @submit.prevent="
                        form.post(confirmUrl, { preserveScroll: true })
                    "
                >
                    <Button
                        type="submit"
                        variant="destructive"
                        class="w-full"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? 'Cancelando...'
                                : appointments.length > 1
                                  ? 'Confirmar cancelamento de todos'
                                  : 'Confirmar cancelamento'
                        }}
                    </Button>
                </form>
                <Button as-child variant="outline" class="w-full">
                    <Link href="/">{{
                        canCancel
                            ? 'Voltar sem cancelar'
                            : 'Voltar e solicitar novo e-mail'
                    }}</Link>
                </Button>
            </div>
        </main>
        <PublicFooter />
    </div>
</template>

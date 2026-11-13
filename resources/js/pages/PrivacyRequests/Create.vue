<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { channels, requestTypes } from '@/types/privacy';
import type { Admin } from '@/types/privacy';

defineProps<{ admins: Admin[] }>();
const form = useForm({
    received_at: '',
    channel: 'phone',
    type: 'full_access',
    other_type: '',
    due_date: '',
    due_reason: '',
    assigned_to: '',
});
const suggested = computed(() => {
    if (form.type !== 'full_access' || !form.received_at) {
        return '';
    }

    const date = new Date(`${form.received_at.slice(0, 10)}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    date.setUTCDate(date.getUTCDate() + 15);

    return date.toISOString().slice(0, 10);
});
watch(suggested, (value, previous) => {
    if (!form.due_date || form.due_date === previous) {
        form.due_date = value;
    }
});
function submit() {
    form.post('/privacy-requests');
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Solicitações LGPD', href: '/privacy-requests' },
            { title: 'Registrar', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Registrar solicitação" />
    <main class="mx-auto max-w-3xl space-y-6 p-4 md:p-6">
        <h1 class="text-2xl font-semibold">Registrar solicitação</h1>
        <p class="text-sm text-muted-foreground">
            Não registre documentos, cópias da resposta ou dados sensíveis. O
            cadastro pode ser vinculado após a confirmação de identidade.
        </p>
        <p class="text-sm">
            A resposta simplificada de confirmação ou acesso é imediata. Para
            declaração completa, sugerimos 15 dias corridos a partir do
            recebimento; confirme o prazo aplicável com assessoria jurídica.
        </p>
        <form class="space-y-5" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="received">Recebimento (horário de Brasília)</Label
                ><Input
                    id="received"
                    v-model="form.received_at"
                    type="datetime-local"
                    required
                /><InputError :message="form.errors.received_at" />
            </div>
            <div class="space-y-2">
                <Label for="channel">Canal de recebimento</Label
                ><select
                    id="channel"
                    v-model="form.channel"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option
                        v-for="(label, value) in channels"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option></select
                ><InputError :message="form.errors.channel" />
            </div>
            <div class="space-y-2">
                <Label for="type">Tipo</Label
                ><select
                    id="type"
                    v-model="form.type"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option
                        v-for="(label, value) in requestTypes"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option></select
                ><InputError :message="form.errors.type" />
            </div>
            <div v-if="form.type === 'other'" class="space-y-2">
                <Label for="other-type">Direito solicitado</Label>
                <Input
                    id="other-type"
                    v-model="form.other_type"
                    maxlength="120"
                    required
                />
                <InputError :message="form.errors.other_type" />
            </div>
            <div class="space-y-2">
                <Label for="due">Prazo de acompanhamento</Label
                ><Input id="due" v-model="form.due_date" type="date" required />
                <p v-if="suggested" class="text-sm text-muted-foreground">
                    Sugestão: {{ suggested }}. Prazo efetivo deve ser validado.
                </p>
                <InputError :message="form.errors.due_date" />
            </div>
            <div class="space-y-2">
                <Label for="reason"
                    >Justificativa para prazo diferente do sugerido</Label
                ><Input
                    id="reason"
                    v-model="form.due_reason"
                    maxlength="500"
                /><InputError :message="form.errors.due_reason" />
            </div>
            <div class="space-y-2">
                <Label for="assigned">Responsável</Label
                ><select
                    id="assigned"
                    v-model="form.assigned_to"
                    class="w-full rounded-md border bg-background p-2"
                    required
                >
                    <option value="" disabled>Selecione</option>
                    <option
                        v-for="admin in admins"
                        :key="admin.id"
                        :value="String(admin.id)"
                    >
                        {{ admin.name }}
                    </option></select
                ><InputError :message="form.errors.assigned_to" />
            </div>
            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Registrar</Button
                ><Button as-child variant="outline"
                    ><Link href="/privacy-requests">Voltar</Link></Button
                >
            </div>
        </form>
    </main>
</template>

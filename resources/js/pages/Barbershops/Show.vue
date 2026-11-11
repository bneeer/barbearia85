<script setup lang="ts">
import { ArrowLeft, EditPencil, Trash } from '@iconoir/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import type { Barbershop, OpeningHours } from '@/types/barbershop';

const props = defineProps<{ barbershop: Barbershop }>();
const deleteForm = useForm<{ barbershop?: string }>({});
const days: { key: keyof OpeningHours; label: string }[] = [
    { key: 'monday', label: 'Segunda-feira' },
    { key: 'tuesday', label: 'Terça-feira' },
    { key: 'wednesday', label: 'Quarta-feira' },
    { key: 'thursday', label: 'Quinta-feira' },
    { key: 'friday', label: 'Sexta-feira' },
    { key: 'saturday', label: 'Sábado' },
    { key: 'sunday', label: 'Domingo' },
];

function destroy() {
    if (confirm('Deseja excluir esta unidade?')) {
        deleteForm.delete(`/barbershops/${props.barbershop.id}`);
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Unidades', href: '/barbershops' },
            { title: 'Detalhes', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Detalhes da unidade" />
    <div class="space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Detalhes da unidade</h1>
        <dl class="space-y-3">
            <div>
                <dt class="text-sm text-muted-foreground">
                    Antecedência para cancelamento pelo cliente
                </dt>
                <dd>{{ barbershop.cancellation_notice_hours }} horas</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Nome</dt>
                <dd>{{ barbershop.name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Descrição</dt>
                <dd class="whitespace-pre-wrap">
                    {{ barbershop.description }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Endereço</dt>
                <dd>{{ barbershop.address }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Telefones</dt>
                <dd v-if="!barbershop.phones?.length">Não informado</dd>
                <dd v-for="(phone, index) in barbershop.phones" :key="index">
                    {{ phone }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">CEP</dt>
                <dd>
                    {{
                        barbershop.zip_code?.replace(
                            /^(\d{5})(\d{3})$/,
                            '$1-$2',
                        )
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Estado geral</dt>
                <dd>{{ barbershop.is_open ? 'Aberta' : 'Fechada' }}</dd>
                <dd class="text-sm text-muted-foreground">
                    Este estado tem prioridade sobre o horário de funcionamento.
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    Horário de funcionamento
                </dt>
                <dd v-for="day in days" :key="day.key" class="text-sm">
                    {{ day.label }}:
                    {{
                        barbershop.opening_hours?.[day.key]?.closed
                            ? 'Fechado'
                            : `${barbershop.opening_hours?.[day.key]?.open} às ${barbershop.opening_hours?.[day.key]?.close}`
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Barbeiros</dt>
                <dd v-if="!barbershop.users?.length">
                    Nenhum barbeiro associado.
                </dd>
                <dd
                    v-for="barber in barbershop.users"
                    :key="barber.id"
                    class="flex items-center gap-3 text-sm"
                >
                    <Avatar class="size-10">
                        <AvatarImage
                            v-if="barber.avatar"
                            :src="barber.avatar"
                            :alt="`Foto de ${barber.name}`"
                        />
                        <AvatarFallback>
                            {{ barber.name.slice(0, 2).toUpperCase() }}
                        </AvatarFallback>
                    </Avatar>
                    <span>{{ barber.name }} ({{ barber.email }})</span>
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Fotos da unidade</dt>
                <dd v-if="!barbershop.photos?.length">
                    Nenhuma foto cadastrada.
                </dd>
                <dd
                    v-for="photo in barbershop.photos"
                    :key="photo.id"
                    class="inline-block p-2"
                >
                    <img
                        :src="photo.url"
                        alt="Foto da unidade"
                        class="size-32 rounded-md border object-cover"
                    />
                </dd>
            </div>
        </dl>
        <div class="flex flex-wrap gap-2">
            <Button as-child>
                <Link :href="`/barbershops/${barbershop.id}/edit`">
                    <EditPencil aria-hidden="true" />Editar
                </Link>
            </Button>
            <Button
                variant="destructive"
                :disabled="deleteForm.processing"
                @click="destroy"
            >
                <Trash aria-hidden="true" />Excluir
            </Button>
            <Button variant="outline" as-child>
                <Link href="/barbershops">
                    <ArrowLeft aria-hidden="true" />Voltar
                </Link>
            </Button>
        </div>
        <InputError :message="deleteForm.errors.barbershop" />
    </div>
</template>

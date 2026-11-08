<script setup lang="ts">
import { ArrowLeft, EditPencil, Trash } from '@iconoir/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { Barber } from '@/types/barber';

defineProps<{ barber: Barber }>();
function destroy(id: number) {
    if (confirm('Deseja excluir este barbeiro?')) {
        router.delete(`/barbers/${id}`);
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Barbeiros', href: '/barbers' },
            { title: 'Detalhes', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Detalhes do barbeiro" />
    <div class="space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Detalhes do barbeiro</h1>
        <img
            v-if="barber.avatar"
            :src="barber.avatar"
            :alt="`Foto de ${barber.name}`"
            class="size-24 rounded-full object-cover"
        />
        <p v-else class="text-sm text-muted-foreground">
            Nenhuma foto cadastrada.
        </p>
        <dl class="space-y-3">
            <div>
                <dt class="text-sm text-muted-foreground">Nome</dt>
                <dd>{{ barber.name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">E-mail</dt>
                <dd>{{ barber.email }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Telefone</dt>
                <dd>{{ barber.phone || 'Não informado' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    Unidades atendidas
                </dt>
                <dd v-if="!barber.barbershops.length">
                    Nenhuma unidade associada.
                </dd>
                <dd
                    v-for="barbershop in barber.barbershops"
                    :key="barbershop.id"
                >
                    {{ barbershop.name }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Serviços</dt>
                <dd v-if="!barber.services.length">
                    Nenhum serviço selecionado.
                </dd>
                <dd v-else>
                    <ul>
                        <li
                            v-for="service in barber.services"
                            :key="service.id"
                        >
                            {{ service.name
                            }}{{ service.is_active ? '' : ' (inativo)' }}
                        </li>
                    </ul>
                </dd>
            </div>
        </dl>
        <div class="flex flex-wrap gap-2">
            <Button as-child
                ><Link :href="`/barbers/${barber.id}/edit`"
                    ><EditPencil aria-hidden="true" />Editar</Link
                ></Button
            >
            <Button variant="destructive" @click="destroy(barber.id)"
                ><Trash aria-hidden="true" />Excluir</Button
            >
            <Button variant="outline" as-child
                ><Link href="/barbers"
                    ><ArrowLeft aria-hidden="true" />Voltar</Link
                ></Button
            >
        </div>
    </div>
</template>

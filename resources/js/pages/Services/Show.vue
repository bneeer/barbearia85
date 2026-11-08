<script setup lang="ts">
import { ArrowLeft, EditPencil, Trash } from '@iconoir/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { Service } from '@/types/service';

defineProps<{ service: Service }>();
const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});
function destroy(id: number) {
    if (confirm('Deseja excluir este serviço?')) {
        router.delete(`/services/${id}`);
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Serviços', href: '/services' },
            { title: 'Detalhes', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Detalhes do serviço" />
    <div class="space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Detalhes do serviço</h1>
        <dl class="space-y-3">
            <div>
                <dt class="text-sm text-muted-foreground">Nome</dt>
                <dd>{{ service.name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Descrição</dt>
                <dd class="whitespace-pre-wrap">{{ service.description }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Preço padrão</dt>
                <dd>{{ currency.format(Number(service.price)) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Duração padrão</dt>
                <dd>{{ service.duration }} min</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Estado</dt>
                <dd>{{ service.is_active ? 'Ativo' : 'Inativo' }}</dd>
            </div>
        </dl>
        <div class="flex flex-wrap gap-2">
            <Button as-child
                ><Link :href="`/services/${service.id}/edit`"
                    ><EditPencil aria-hidden="true" />Editar</Link
                ></Button
            >
            <Button variant="destructive" @click="destroy(service.id)"
                ><Trash aria-hidden="true" />Excluir</Button
            >
            <Button variant="outline" as-child
                ><Link href="/services"
                    ><ArrowLeft aria-hidden="true" />Voltar</Link
                ></Button
            >
        </div>
    </div>
</template>

<script setup lang="ts">
import { ArrowLeft, EditPencil, Trash } from '@iconoir/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { Product } from '@/types/product';

defineProps<{ product: Product }>();
const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});
function destroy(id: number) {
    if (confirm('Deseja excluir este produto?')) {
        router.delete(`/products/${id}`);
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Produtos', href: '/products' },
            { title: 'Detalhes', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Detalhes do produto" />
    <div class="space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Detalhes do produto</h1>
        <dl class="space-y-3">
            <div>
                <dt class="text-sm text-muted-foreground">Nome</dt>
                <dd>{{ product.name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Marca</dt>
                <dd>{{ product.brand }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Descrição</dt>
                <dd class="whitespace-pre-wrap">{{ product.description }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Preço</dt>
                <dd>{{ currency.format(Number(product.price)) }}</dd>
            </div>
        </dl>
        <div class="flex flex-wrap gap-2">
            <Button as-child
                ><Link :href="`/products/${product.id}/edit`"
                    ><EditPencil aria-hidden="true" />Editar</Link
                ></Button
            >
            <Button variant="destructive" @click="destroy(product.id)"
                ><Trash aria-hidden="true" />Excluir</Button
            >
            <Button variant="outline" as-child
                ><Link href="/products"
                    ><ArrowLeft aria-hidden="true" />Voltar</Link
                ></Button
            >
        </div>
    </div>
</template>

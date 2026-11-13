<script setup lang="ts">
import { ArrowLeft, FloppyDisk } from '@iconoir/vue';
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Product } from '@/types/product';

const props = defineProps<{ product?: Product }>();
const form = useForm({
    name: props.product?.name ?? '',
    brand: props.product?.brand ?? '',
    description: props.product?.description ?? '',
    price: props.product?.price ?? '',
    min_stock: props.product?.min_stock ?? 0,
});

function submit() {
    if (props.product) {
        form.put(`/products/${props.product.id}`);
    } else {
        form.post('/products');
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Nome</Label>
            <Input id="name" v-model="form.name" maxlength="255" required />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="brand">Marca</Label>
            <Input id="brand" v-model="form.brand" maxlength="255" required />
            <InputError :message="form.errors.brand" />
        </div>
        <div class="grid gap-2">
            <Label for="description">Descrição</Label>
            <textarea
                id="description"
                v-model="form.description"
                class="min-h-24 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                required
            />
            <InputError :message="form.errors.description" />
        </div>
        <div class="grid gap-2">
            <Label for="price">Preço (R$)</Label>
            <Input
                id="price"
                v-model="form.price"
                type="number"
                min="0"
                max="99999999.99"
                step="0.01"
                required
            />
            <InputError :message="form.errors.price" />
        </div>
        <div class="grid gap-2">
            <Label for="min_stock">Estoque mínimo para alerta</Label>
            <Input
                id="min_stock"
                v-model.number="form.min_stock"
                type="number"
                min="0"
                max="999999999"
                step="1"
                required
            />
            <InputError :message="form.errors.min_stock" />
            <p class="text-sm text-muted-foreground">
                O saldo inicial é zero. Registre uma entrada na tela de Estoque.
            </p>
        </div>
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing"
                ><FloppyDisk aria-hidden="true" />Salvar</Button
            >
            <Button variant="outline" as-child
                ><Link href="/products"
                    ><ArrowLeft aria-hidden="true" />Voltar</Link
                ></Button
            >
        </div>
    </form>
</template>

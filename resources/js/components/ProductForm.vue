<script setup lang="ts">
import { FloppyDisk } from '@iconoir/vue';
import { useForm } from '@inertiajs/vue3';
import InitialStockFields from '@/components/InitialStockFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Product } from '@/types/product';

const props = defineProps<{ product?: Product }>();
const emit = defineEmits<{ saved: []; cancel: [] }>();
const form = useForm({
    name: props.product?.name ?? '',
    brand: props.product?.brand ?? '',
    description: props.product?.description ?? '',
    price: props.product?.price ?? '',
    min_stock: props.product?.min_stock ?? 0,
    initial_entry: false,
    initial_quantity: '1',
    initial_total_cost: '',
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('saved') };

    if (props.product) {
        form.put(`/products/${props.product.id}`, options);
    } else {
        form.post('/products', options);
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
            <Label for="price">Preço de venda (R$)</Label>
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
            <p v-if="product" class="text-sm text-muted-foreground">
                Saldo e custo médio só podem ser alterados por movimentações.
            </p>
        </div>
        <InitialStockFields
            v-if="!product"
            v-model:enabled="form.initial_entry"
            v-model:quantity="form.initial_quantity"
            v-model:total-cost="form.initial_total_cost"
            unit="un"
            id-prefix="product"
            :errors="form.errors"
        />
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing"
                ><FloppyDisk aria-hidden="true" />Salvar</Button
            >
            <Button type="button" variant="outline" @click="emit('cancel')"
                >Cancelar</Button
            >
        </div>
    </form>
</template>

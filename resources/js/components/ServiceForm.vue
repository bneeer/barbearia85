<script setup lang="ts">
import { ArrowLeft, FloppyDisk } from '@iconoir/vue';
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Service } from '@/types/service';

const props = defineProps<{ service?: Service }>();
const form = useForm({
    name: props.service?.name ?? '',
    description: props.service?.description ?? '',
    price: props.service?.price ?? '',
    duration: props.service?.duration ?? '',
    is_active: props.service?.is_active ?? true,
});

function submit() {
    if (props.service) {
        form.put(`/services/${props.service.id}`);
    } else {
        form.post('/services');
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
            <Label for="price">Preço padrão (R$)</Label>
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
            <Label for="duration">Duração padrão (minutos)</Label>
            <Input
                id="duration"
                v-model="form.duration"
                type="number"
                min="1"
                max="2147483647"
                step="1"
                required
            />
            <InputError :message="form.errors.duration" />
        </div>
        <div class="grid gap-2">
            <Label for="is_active">Estado</Label>
            <select
                id="is_active"
                v-model="form.is_active"
                class="h-9 w-full rounded-md border bg-background px-3 text-sm"
            >
                <option :value="true">Ativo</option>
                <option :value="false">Inativo</option>
            </select>
            <InputError :message="form.errors.is_active" />
        </div>
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing"
                ><FloppyDisk aria-hidden="true" />Salvar</Button
            >
            <Button variant="outline" as-child
                ><Link href="/services"
                    ><ArrowLeft aria-hidden="true" />Voltar</Link
                ></Button
            >
        </div>
    </form>
</template>

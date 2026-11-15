<script setup lang="ts">
import { ArrowLeft, FloppyDisk } from '@iconoir/vue';
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Service } from '@/types/service';

type SupplyOption = { id: number; name: string; unit: string };
const props = defineProps<{ service?: Service; supplies: SupplyOption[] }>();
const form = useForm({
    name: props.service?.name ?? '',
    description: props.service?.description ?? '',
    price: props.service?.price ?? '',
    duration: props.service?.duration ?? '',
    commission_percent: props.service?.commission_percent ?? '0.00',
    supply_items:
        props.service?.supplies?.map((supply) => ({
            supply_id: supply.id,
            quantity: supply.pivot.quantity,
        })) ?? [],
    is_active: props.service?.is_active ?? true,
});
const selectedSupplyIds = () => form.supply_items.map((item) => item.supply_id);

function addSupply() {
    const option = props.supplies.find(
        (supply) => !selectedSupplyIds().includes(supply.id),
    );

    if (option) {
        form.supply_items.push({ supply_id: option.id, quantity: '1' });
    }
}

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
            <Label for="commission-percent">Comissão do barbeiro (%)</Label>
            <Input
                id="commission-percent"
                v-model="form.commission_percent"
                type="number"
                min="0"
                max="100"
                step="0.01"
                required
            />
            <InputError :message="form.errors.commission_percent" />
        </div>
        <fieldset class="space-y-3 rounded-md border p-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <legend class="text-sm font-medium">
                        Insumos por atendimento
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        O consumo será sugerido no checkout e só baixado após
                        confirmação.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.supply_items.length >= supplies.length"
                    @click="addSupply"
                    >Adicionar insumo</Button
                >
            </div>
            <p v-if="!supplies.length" class="text-sm text-muted-foreground">
                Cadastre insumos no estoque antes de criar uma composição.
            </p>
            <div
                v-for="(item, index) in form.supply_items"
                :key="item.supply_id"
                class="grid gap-2 sm:grid-cols-[1fr_8rem_auto]"
            >
                <select
                    v-model.number="item.supply_id"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    :aria-label="`Insumo ${index + 1}`"
                    required
                >
                    <option
                        v-for="supply in supplies.filter(
                            (option) =>
                                option.id === item.supply_id ||
                                !selectedSupplyIds().includes(option.id),
                        )"
                        :key="supply.id"
                        :value="supply.id"
                    >
                        {{ supply.name }} ({{ supply.unit }})
                    </option>
                </select>
                <Input
                    v-model="item.quantity"
                    type="number"
                    min="0.001"
                    :step="
                        supplies.find((supply) => supply.id === item.supply_id)
                            ?.unit === 'un'
                            ? '1'
                            : '0.001'
                    "
                    required
                    :aria-label="`Quantidade do insumo ${index + 1}`"
                />
                <Button
                    type="button"
                    variant="outline"
                    @click="form.supply_items.splice(index, 1)"
                    >Remover</Button
                >
            </div>
            <InputError :message="form.errors.supply_items" />
            <InputError
                v-for="(message, key) in form.errors"
                :key="key"
                :message="
                    String(key).startsWith('supply_items.')
                        ? message
                        : undefined
                "
            />
        </fieldset>
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

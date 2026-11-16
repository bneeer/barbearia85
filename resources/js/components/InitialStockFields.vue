<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const enabled = defineModel<boolean>('enabled', { required: true });
const quantity = defineModel<string>('quantity', { required: true });
const totalCost = defineModel<string>('totalCost', { required: true });
const props = defineProps<{
    unit: string;
    errors: { initial_quantity?: string; initial_total_cost?: string };
    idPrefix: string;
}>();
const unitCost = computed(() =>
    Number(quantity.value) > 0 && totalCost.value !== ''
        ? new Intl.NumberFormat('pt-BR', {
              style: 'currency',
              currency: 'BRL',
              maximumFractionDigits: 5,
          }).format(Number(totalCost.value) / Number(quantity.value))
        : null,
);
</script>

<template>
    <fieldset class="space-y-3 rounded-md border p-3">
        <legend class="px-1 text-sm font-medium">
            Estoque inicial (opcional)
        </legend>
        <label class="flex items-center gap-2 text-sm">
            <input v-model="enabled" type="checkbox" />
            Registrar entrada inicial junto com o cadastro
        </label>
        <div v-if="enabled" class="grid gap-3 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-initial-quantity`"
                    >Quantidade ({{ props.unit }})</Label
                >
                <Input
                    :id="`${idPrefix}-initial-quantity`"
                    v-model="quantity"
                    type="number"
                    :min="props.unit === 'un' ? 1 : 0.001"
                    :step="props.unit === 'un' ? 1 : 0.001"
                    :max="props.unit === 'un' ? 999999999 : 999999999.999"
                    required
                />
                <InputError :message="errors.initial_quantity" />
            </div>
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-initial-cost`"
                    >Custo total da entrada (R$)</Label
                >
                <Input
                    :id="`${idPrefix}-initial-cost`"
                    v-model="totalCost"
                    type="number"
                    min="0"
                    max="999999999.99"
                    step="0.01"
                    required
                />
                <InputError :message="errors.initial_total_cost" />
            </div>
            <p
                v-if="unitCost"
                class="text-xs text-muted-foreground sm:col-span-2"
            >
                Custo unitário estimado: {{ unitCost }} / {{ props.unit }}. O
                custo médio será calculado no servidor.
            </p>
        </div>
        <p v-else class="text-xs text-muted-foreground">
            O item será cadastrado com saldo zero e custo médio desconhecido.
        </p>
    </fieldset>
</template>

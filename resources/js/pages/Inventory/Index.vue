<script setup lang="ts">
import { EditPencil, Plus, Search } from '@iconoir/vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Boxes, Package, TrendingDown, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Item = {
    id: number;
    name: string;
    brand: string;
    description: string | null;
    price: string | null;
    average_cost_milli_cents: number | null;
    stock_quantity: number | string;
    min_stock: number | string;
    unit: string | null;
    type: 'product' | 'supply';
};
type PageLink = { url: string | null; label: string; active: boolean };
type Movement = {
    id: number;
    type: 'entry' | 'exit' | 'adjustment';
    cost_total_cents: number | null;
    quantity: string;
    balance_after: string;
    reason: string;
    item_name: string;
    item_type: 'product' | 'supply';
    user_name: string;
    created_at: string;
    unit: string | null;
};

const props = defineProps<{
    items: {
        data: Item[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    movements: {
        data: Movement[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; type: string; status: string };
    counts: { products: number; supplies: number; low_stock: number };
}>();

const search = ref(props.filters.search);
const typeFilter = ref(props.filters.type);
const statusFilter = ref(props.filters.status);
const supplyOpen = ref(false);
const movementOpen = ref(false);
const editingSupplyId = ref<number | null>(null);
const selectedItem = ref<Item | null>(null);
const supplyForm = useForm({
    name: '',
    brand: '',
    description: '',
    unit: 'un',
    min_stock: '0',
});
const movementForm = useForm({
    item_type: 'product' as 'product' | 'supply',
    item_id: 0,
    type: 'entry',
    quantity: '1',
    total_cost: '',
    reason: '',
});
const selectedUnit = computed(() =>
    selectedItem.value?.type === 'product'
        ? 'un'
        : (selectedItem.value?.unit ?? 'un'),
);
const quantityStep = computed(() =>
    selectedItem.value?.type === 'product' || selectedUnit.value === 'un'
        ? '1'
        : '0.001',
);
const numberFormat = new Intl.NumberFormat('pt-BR', {
    maximumFractionDigits: 3,
});
const currencyFormat = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});
const dateFormat = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

watch(
    () => movementForm.type,
    (type) => {
        if (selectedItem.value) {
            if (type === 'adjustment') {
                movementForm.quantity = String(
                    selectedItem.value.stock_quantity,
                );
            } else if (type === 'exit') {
                movementForm.quantity = String(
                    Math.min(1, Number(selectedItem.value.stock_quantity)),
                );
            }
        }
    },
);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Estoque', href: '/inventory' }] },
});

function filter() {
    router.get(
        '/inventory',
        {
            search: search.value,
            type: typeFilter.value,
            status: statusFilter.value,
        },
        { preserveState: true, replace: true },
    );
}

function openSupply(item?: Item) {
    editingSupplyId.value = item?.type === 'supply' ? item.id : null;
    supplyForm.clearErrors();
    supplyForm.name = item?.name ?? '';
    supplyForm.brand = item?.brand ?? '';
    supplyForm.description = item?.description ?? '';
    supplyForm.unit = item?.unit ?? 'un';
    supplyForm.min_stock = String(item?.min_stock ?? '0');
    supplyOpen.value = true;
}

function submitSupply() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            supplyOpen.value = false;
            supplyForm.reset();
        },
    };

    if (editingSupplyId.value) {
        supplyForm.put(`/inventory/supplies/${editingSupplyId.value}`, options);
    } else {
        supplyForm.post('/inventory/supplies', options);
    }
}

function openMovement(item: Item) {
    selectedItem.value = item;
    movementForm.clearErrors();
    movementForm.item_type = item.type;
    movementForm.item_id = item.id;
    movementForm.type = 'entry';
    movementForm.quantity = item.type === 'product' ? '1' : '1';
    movementForm.total_cost = '';
    movementForm.reason = '';
    movementOpen.value = true;
}

function submitMovement() {
    movementForm.post('/inventory/movements', {
        preserveScroll: true,
        onSuccess: () => {
            movementOpen.value = false;
            movementForm.reset();
        },
    });
}

function quantity(value: string | number, unit: string | null) {
    return `${numberFormat.format(Number(value))} ${unit ?? 'un'}`;
}

function movementLabel(type: Movement['type']) {
    return {
        entry: 'Entrada',
        exit: 'Saída',
        adjustment: 'Ajuste',
    }[type];
}

function movementQuantity(movement: Movement) {
    const amount = numberFormat.format(Math.abs(Number(movement.quantity)));

    if (movement.type === 'entry') {
        return `+${amount}`;
    }

    if (movement.type === 'exit') {
        return `-${amount}`;
    }

    return `${Number(movement.quantity) > 0 ? '+' : ''}${numberFormat.format(Number(movement.quantity))}`;
}
</script>

<template>
    <Head title="Estoque" />
    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Estoque</h1>
                <p class="text-sm text-muted-foreground">
                    Controle produtos comercializáveis, insumos e movimentações.
                </p>
            </div>
            <Button @click="openSupply()">
                <Plus aria-hidden="true" />Cadastrar insumo
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="flex items-center gap-3 rounded-lg border p-4">
                <Package
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <p class="text-sm text-muted-foreground">Produtos</p>
                    <p class="text-xl font-semibold">{{ counts.products }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-lg border p-4">
                <Boxes
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <p class="text-sm text-muted-foreground">Insumos</p>
                    <p class="text-xl font-semibold">{{ counts.supplies }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-lg border p-4">
                <TriangleAlert
                    class="size-5 text-amber-600"
                    aria-hidden="true"
                />
                <div>
                    <p class="text-sm text-muted-foreground">
                        Abaixo do estoque mínimo
                    </p>
                    <p class="text-xl font-semibold">{{ counts.low_stock }}</p>
                </div>
            </div>
        </div>

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">Itens de estoque</h2>
                <p class="text-sm text-muted-foreground">
                    Produtos existentes começam com saldo zero; registre uma
                    entrada para disponibilizar vendas.
                </p>
            </div>
            <form class="flex flex-wrap gap-2" @submit.prevent="filter">
                <div class="flex min-w-56 flex-1 gap-2">
                    <Input
                        v-model="search"
                        aria-label="Buscar itens"
                        placeholder="Buscar por nome ou marca"
                        maxlength="100"
                    />
                    <Button type="submit"
                        ><Search aria-hidden="true" />Buscar</Button
                    >
                </div>
                <select
                    v-model="typeFilter"
                    aria-label="Tipo de item"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    @change="filter"
                >
                    <option value="">Todos os tipos</option>
                    <option value="product">Produtos</option>
                    <option value="supply">Insumos</option>
                </select>
                <select
                    v-model="statusFilter"
                    aria-label="Situação do estoque"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    @change="filter"
                >
                    <option value="all">Todos os saldos</option>
                    <option value="low">Abaixo do mínimo</option>
                    <option value="out">Sem estoque</option>
                    <option value="available">Com saldo</option>
                </select>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted text-muted-foreground">
                        <tr>
                            <th class="p-4">Item</th>
                            <th class="p-4">Tipo</th>
                            <th class="p-4">Preço</th>
                            <th class="p-4">Saldo</th>
                            <th class="p-4">Mínimo</th>
                            <th class="p-4">Situação</th>
                            <th class="p-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in items.data"
                            :key="`${item.type}-${item.id}`"
                            class="border-t"
                        >
                            <td class="p-4">
                                <p class="font-medium">{{ item.name }}</p>
                                <p
                                    v-if="item.brand"
                                    class="text-muted-foreground"
                                >
                                    {{ item.brand }}
                                </p>
                            </td>
                            <td class="p-4">
                                {{
                                    item.type === 'product'
                                        ? 'Produto'
                                        : 'Insumo'
                                }}
                            </td>
                            <td class="p-4">
                                {{
                                    item.price
                                        ? currencyFormat.format(
                                              Number(item.price),
                                          )
                                        : '—'
                                }}
                                <p
                                    v-if="
                                        item.average_cost_milli_cents !== null
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Custo médio:
                                    {{
                                        currencyFormat.format(
                                            item.average_cost_milli_cents /
                                                100000,
                                        )
                                    }}
                                    / {{ item.unit ?? 'un' }}
                                </p>
                                <p v-else class="text-xs text-muted-foreground">
                                    Custo médio desconhecido
                                </p>
                            </td>
                            <td class="p-4">
                                {{ quantity(item.stock_quantity, item.unit) }}
                            </td>
                            <td class="p-4">
                                {{ quantity(item.min_stock, item.unit) }}
                            </td>
                            <td class="p-4">
                                <span
                                    v-if="Number(item.stock_quantity) === 0"
                                    class="font-medium text-destructive"
                                    >Sem estoque</span
                                >
                                <span
                                    v-else-if="
                                        Number(item.stock_quantity) <=
                                        Number(item.min_stock)
                                    "
                                    class="font-medium text-amber-700"
                                    >Estoque baixo</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >Disponível</span
                                >
                            </td>
                            <td class="space-x-3 p-4 whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-primary underline"
                                    @click="openMovement(item)"
                                >
                                    <TrendingDown
                                        aria-hidden="true"
                                        class="size-4"
                                    />
                                    Movimentar
                                </button>
                                <button
                                    v-if="item.type === 'supply'"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-primary underline"
                                    @click="openSupply(item)"
                                >
                                    <EditPencil
                                        aria-hidden="true"
                                        class="size-4"
                                    />
                                    Editar
                                </button>
                                <Link
                                    v-else
                                    class="inline-flex items-center gap-1 text-primary underline"
                                    :href="`/products/${item.id}/edit`"
                                    ><EditPencil
                                        aria-hidden="true"
                                        class="size-4"
                                    />Editar produto</Link
                                >
                            </td>
                        </tr>
                        <tr v-if="!items.data.length">
                            <td colspan="7" class="p-4 text-muted-foreground">
                                Nenhum item encontrado.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="flex flex-wrap items-center justify-between gap-4 text-sm"
            >
                <p>
                    {{
                        items.total
                            ? `Exibindo ${items.from} a ${items.to} de ${items.total}`
                            : '0 resultados'
                    }}
                </p>
                <nav
                    aria-label="Paginação de itens"
                    class="flex flex-wrap gap-1"
                >
                    <template
                        v-for="(link, index) in items.links"
                        :key="`item-${index}`"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :aria-current="link.active ? 'page' : undefined"
                            :class="[
                                'rounded border px-3 py-1',
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-muted',
                            ]"
                        >
                            {{
                                index === 0
                                    ? 'Anterior'
                                    : index === items.links.length - 1
                                      ? 'Próxima'
                                      : link.label
                            }}
                        </Link>
                    </template>
                </nav>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">Movimentações recentes</h2>
                <p class="text-sm text-muted-foreground">
                    Entradas, saídas de venda e ajustes registrados no estoque.
                </p>
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted text-muted-foreground">
                        <tr>
                            <th class="p-4">Data</th>
                            <th class="p-4">Item</th>
                            <th class="p-4">Movimentação</th>
                            <th class="p-4">Variação</th>
                            <th class="p-4">Saldo após</th>
                            <th class="p-4">Motivo / responsável</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="movement in movements.data"
                            :key="movement.id"
                            class="border-t"
                        >
                            <td class="p-4 whitespace-nowrap">
                                {{
                                    dateFormat.format(
                                        new Date(movement.created_at),
                                    )
                                }}
                            </td>
                            <td class="p-4">{{ movement.item_name }}</td>
                            <td class="p-4">
                                {{ movementLabel(movement.type) }}
                            </td>
                            <td class="p-4">
                                {{ movementQuantity(movement) }}
                                {{ movement.unit ?? 'un' }}
                            </td>
                            <td class="p-4">
                                {{
                                    numberFormat.format(
                                        Number(movement.balance_after),
                                    )
                                }}
                                {{ movement.unit }}
                            </td>
                            <td class="p-4">
                                <p>{{ movement.reason }}</p>
                                <p
                                    v-if="movement.cost_total_cents !== null"
                                    class="text-muted-foreground"
                                >
                                    Custo da movimentação:
                                    {{
                                        currencyFormat.format(
                                            movement.cost_total_cents / 100,
                                        )
                                    }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ movement.user_name }}
                                </p>
                            </td>
                        </tr>
                        <tr v-if="!movements.data.length">
                            <td colspan="6" class="p-4 text-muted-foreground">
                                Nenhuma movimentação registrada.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="movements.total > 10"
                aria-label="Paginação de movimentações"
                class="flex flex-wrap gap-1"
            >
                <template
                    v-for="(link, index) in movements.links"
                    :key="`movement-${index}`"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        class="rounded border px-3 py-1 hover:bg-muted"
                        >{{
                            index === 0
                                ? 'Anterior'
                                : index === movements.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}</Link
                    >
                </template>
            </nav>
        </section>
    </div>

    <Dialog v-model:open="supplyOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{ editingSupplyId ? 'Editar insumo' : 'Cadastrar insumo' }}
                </DialogTitle>
                <DialogDescription>
                    O saldo começa em zero. Registre uma entrada após salvar.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submitSupply">
                <div class="grid gap-2">
                    <Label for="supply-name">Nome</Label>
                    <Input
                        id="supply-name"
                        v-model="supplyForm.name"
                        maxlength="255"
                        required
                    />
                    <InputError :message="supplyForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="supply-brand">Marca (opcional)</Label>
                    <Input
                        id="supply-brand"
                        v-model="supplyForm.brand"
                        maxlength="255"
                    />
                    <InputError :message="supplyForm.errors.brand" />
                </div>
                <div class="grid gap-2">
                    <Label for="supply-description">Descrição (opcional)</Label>
                    <textarea
                        id="supply-description"
                        v-model="supplyForm.description"
                        maxlength="5000"
                        class="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="supplyForm.errors.description" />
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="supply-unit">Unidade</Label>
                        <select
                            id="supply-unit"
                            v-model="supplyForm.unit"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="un">unidade (un)</option>
                            <option value="ml">mililitro (ml)</option>
                            <option value="g">grama (g)</option>
                            <option value="L">litro (L)</option>
                            <option value="kg">quilograma (kg)</option>
                        </select>
                        <InputError :message="supplyForm.errors.unit" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="supply-minimum">Estoque mínimo</Label>
                        <Input
                            id="supply-minimum"
                            v-model="supplyForm.min_stock"
                            type="number"
                            min="0"
                            max="999999999.999"
                            :step="supplyForm.unit === 'un' ? '1' : '0.001'"
                            required
                        />
                        <InputError :message="supplyForm.errors.min_stock" />
                    </div>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="supplyForm.processing">
                        {{
                            editingSupplyId ? 'Salvar alterações' : 'Cadastrar'
                        }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="supplyOpen = false"
                        >Cancelar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="movementOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Movimentar estoque</DialogTitle>
                <DialogDescription>
                    {{ selectedItem?.name }} · Saldo atual:
                    {{
                        quantity(
                            selectedItem?.stock_quantity ?? 0,
                            selectedUnit,
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submitMovement">
                <div class="grid gap-2">
                    <Label for="movement-type">Tipo de movimentação</Label>
                    <select
                        id="movement-type"
                        v-model="movementForm.type"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="entry">Entrada</option>
                        <option value="exit">Saída manual</option>
                        <option value="adjustment">
                            Ajuste para saldo contado
                        </option>
                    </select>
                    <InputError :message="movementForm.errors.type" />
                </div>
                <div class="grid gap-2">
                    <Label for="movement-quantity">
                        {{
                            movementForm.type === 'adjustment'
                                ? `Saldo contado (${selectedUnit})`
                                : `Quantidade (${selectedUnit})`
                        }}
                    </Label>
                    <Input
                        id="movement-quantity"
                        v-model="movementForm.quantity"
                        type="number"
                        :min="
                            movementForm.type === 'adjustment'
                                ? 0
                                : quantityStep === '1'
                                  ? 1
                                  : 0.001
                        "
                        :max="
                            movementForm.type === 'exit'
                                ? Number(selectedItem?.stock_quantity ?? 0)
                                : 999999999.999
                        "
                        :step="quantityStep"
                        required
                    />
                    <InputError :message="movementForm.errors.quantity" />
                </div>
                <div v-if="movementForm.type === 'entry'" class="grid gap-2">
                    <Label for="movement-total-cost"
                        >Custo total da entrada (R$)</Label
                    >
                    <Input
                        id="movement-total-cost"
                        v-model="movementForm.total_cost"
                        type="number"
                        min="0"
                        max="999999999.99"
                        step="0.01"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        Usado para recalcular o custo médio ponderado do saldo.
                    </p>
                    <InputError :message="movementForm.errors.total_cost" />
                </div>
                <div class="grid gap-2">
                    <Label for="movement-reason">Motivo</Label>
                    <Input
                        id="movement-reason"
                        v-model="movementForm.reason"
                        maxlength="255"
                        required
                    />
                    <InputError :message="movementForm.errors.reason" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="movementForm.processing">
                        Registrar movimentação
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="movementOpen = false"
                        >Cancelar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

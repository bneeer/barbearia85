<script setup lang="ts">
import { EditPencil, Plus, Search } from '@iconoir/vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Boxes, Package, TrendingDown, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import InitialStockFields from '@/components/InitialStockFields.vue';
import InputError from '@/components/InputError.vue';
import ProductForm from '@/components/ProductForm.vue';
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
import type { Product } from '@/types/product';

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
    dialog: {
        action: 'create' | 'edit' | 'show' | null;
        item_type: 'product' | 'supply' | null;
        item: Item | null;
    };
}>();

const page = usePage();
const search = ref(props.filters.search);
const typeFilter = ref(props.filters.type);
const statusFilter = ref(props.filters.status);
const supplyOpen = ref(false);
const productOpen = ref(false);
const detailsOpen = ref(false);
const archiveOpen = ref(false);
const editingProduct = ref<Product>();
const detailItem = ref<Item | null>(null);
const archiveItem = ref<Item | null>(null);
const archiveForm = useForm({});
const archiveError = ref('');
const movementOpen = ref(false);
const editingSupplyId = ref<number | null>(null);
const selectedItem = ref<Item | null>(null);
const supplyForm = useForm({
    name: '',
    brand: '',
    description: '',
    unit: 'un',
    min_stock: '0',
    initial_entry: false,
    initial_quantity: '1',
    initial_total_cost: '',
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
    supplyForm.initial_entry = false;
    supplyForm.initial_quantity = '1';
    supplyForm.initial_total_cost = '';
    supplyOpen.value = true;
}

function openProduct(item?: Item) {
    editingProduct.value = item
        ? {
              id: item.id,
              name: item.name,
              brand: item.brand,
              description: item.description ?? '',
              price: item.price ?? '',
              stock_quantity: Number(item.stock_quantity),
              min_stock: Number(item.min_stock),
          }
        : undefined;
    productOpen.value = true;
}

function openDetails(item: Item) {
    detailItem.value = item;
    detailsOpen.value = true;
}

function openArchive(item: Item) {
    archiveItem.value = item;
    archiveError.value = '';
    archiveOpen.value = true;
}

function submitArchive() {
    const item = archiveItem.value;

    if (!item) {
        return;
    }

    archiveForm.delete(
        item.type === 'product'
            ? `/products/${item.id}`
            : `/inventory/supplies/${item.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                archiveOpen.value = false;
                detailsOpen.value = false;
            },
            onError: (errors) => {
                archiveError.value =
                    errors.archive ?? Object.values(errors).join(' ');
            },
        },
    );
}

watch(
    () => props.dialog,
    (dialog) => {
        if (dialog.action === 'create') {
            if (dialog.item_type === 'product') {
                openProduct();
            } else {
                openSupply();
            }
        } else if (dialog.item) {
            if (dialog.action === 'show') {
                openDetails(dialog.item);
            } else if (dialog.item.type === 'product') {
                openProduct(dialog.item);
            } else {
                openSupply(dialog.item);
            }
        }
    },
    { immediate: true, once: true },
);

watch([productOpen, supplyOpen, detailsOpen], ([product, supply, details]) => {
    if (product || supply || details) {
        return;
    }

    const url = new URL(page.url, window.location.origin);

    if (!url.searchParams.has('action')) {
        return;
    }

    for (const key of ['action', 'item_type', 'item_id']) {
        url.searchParams.delete(key);
    }

    router.get(
        `${url.pathname}${url.search}`,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

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
            <div class="flex flex-wrap gap-2">
                <Button @click="openProduct()">
                    <Plus aria-hidden="true" />Cadastrar produto
                </Button>
                <Button variant="outline" @click="openSupply()">
                    <Plus aria-hidden="true" />Cadastrar insumo
                </Button>
            </div>
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
                    Cadastre produtos e insumos aqui. Registre entradas com
                    custo para disponibilizar saldo e calcular o custo médio.
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
                            <th class="p-4">Preço de venda</th>
                            <th class="p-4">Custo médio / unidade</th>
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
                            </td>
                            <td class="p-4">
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
                                    {{
                                        currencyFormat.format(
                                            item.average_cost_milli_cents /
                                                100000,
                                        )
                                    }}
                                    / {{ item.unit ?? 'un' }}
                                </p>
                                <p v-else class="text-xs text-muted-foreground">
                                    Desconhecido
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
                                    type="button"
                                    class="inline-flex items-center gap-1 text-primary underline"
                                    @click="
                                        item.type === 'supply'
                                            ? openSupply(item)
                                            : openProduct(item)
                                    "
                                >
                                    <EditPencil
                                        aria-hidden="true"
                                        class="size-4"
                                    />
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    class="text-primary underline"
                                    @click="openDetails(item)"
                                >
                                    Detalhes
                                </button>
                                <button
                                    type="button"
                                    class="text-destructive underline"
                                    @click="openArchive(item)"
                                >
                                    Arquivar
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!items.data.length">
                            <td colspan="8" class="p-4 text-muted-foreground">
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

    <Dialog v-model:open="productOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{
                    editingProduct ? 'Editar produto' : 'Cadastrar produto'
                }}</DialogTitle>
                <DialogDescription
                    >Dados do produto, preço de venda e estoque
                    mínimo.</DialogDescription
                >
            </DialogHeader>
            <ProductForm
                v-if="productOpen"
                :key="editingProduct?.id ?? 'new'"
                :product="editingProduct"
                @saved="productOpen = false"
                @cancel="productOpen = false"
            />
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="detailsOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ detailItem?.name }}</DialogTitle>
                <DialogDescription>{{
                    detailItem?.type === 'product'
                        ? 'Produto comercializável'
                        : 'Insumo de atendimento'
                }}</DialogDescription>
            </DialogHeader>
            <div v-if="detailItem" class="space-y-3 text-sm">
                <p v-if="detailItem.brand">
                    <strong>Marca:</strong> {{ detailItem.brand }}
                </p>
                <p class="whitespace-pre-wrap">
                    {{ detailItem.description || 'Sem descrição.' }}
                </p>
                <p>
                    <strong>Saldo:</strong>
                    {{ quantity(detailItem.stock_quantity, detailItem.unit) }}
                </p>
                <p>
                    <strong>Mínimo:</strong>
                    {{ quantity(detailItem.min_stock, detailItem.unit) }}
                </p>
                <p v-if="detailItem.type === 'product'">
                    <strong>Preço de venda:</strong>
                    {{ currencyFormat.format(Number(detailItem.price)) }}
                </p>
                <p>
                    <strong
                        >Custo médio / {{ detailItem.unit ?? 'un' }}:</strong
                    >
                    {{
                        detailItem.average_cost_milli_cents === null
                            ? 'Desconhecido'
                            : currencyFormat.format(
                                  detailItem.average_cost_milli_cents / 100000,
                              )
                    }}
                </p>
            </div>
            <DialogFooter
                ><Button variant="outline" @click="detailsOpen = false"
                    >Fechar</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="archiveOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Arquivar {{ archiveItem?.name }}?</DialogTitle>
                <DialogDescription
                    >O histórico será preservado. O saldo deve estar zerado e
                    insumos não podem estar vinculados a serviços
                    ativos.</DialogDescription
                >
            </DialogHeader>
            <InputError :message="archiveError" />
            <p
                v-if="Number(archiveItem?.stock_quantity) > 0"
                class="text-sm text-destructive"
            >
                Registre uma movimentação para zerar o saldo antes de arquivar.
            </p>
            <DialogFooter>
                <Button
                    variant="destructive"
                    :disabled="
                        archiveForm.processing ||
                        Number(archiveItem?.stock_quantity) > 0
                    "
                    @click="submitArchive"
                    >Arquivar</Button
                >
                <Button variant="outline" @click="archiveOpen = false"
                    >Cancelar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="supplyOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>
                    {{ editingSupplyId ? 'Editar insumo' : 'Cadastrar insumo' }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        editingSupplyId
                            ? 'Saldo e custo médio só podem ser alterados por movimentações.'
                            : 'Inclua uma entrada inicial opcional ou registre entradas após salvar.'
                    }}
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
                <InitialStockFields
                    v-if="!editingSupplyId"
                    v-model:enabled="supplyForm.initial_entry"
                    v-model:quantity="supplyForm.initial_quantity"
                    v-model:total-cost="supplyForm.initial_total_cost"
                    :unit="supplyForm.unit"
                    id-prefix="supply"
                    :errors="supplyForm.errors"
                />
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
                        <span
                            v-if="
                                Number(movementForm.quantity) > 0 &&
                                movementForm.total_cost !== ''
                            "
                        >
                            Custo unitário estimado:
                            {{
                                currencyFormat.format(
                                    Number(movementForm.total_cost) /
                                        Number(movementForm.quantity),
                                )
                            }}
                            / {{ selectedUnit }}.
                        </span>
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

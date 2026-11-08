<script setup lang="ts">
import { EditPencil, Eye, Plus, Search } from '@iconoir/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Product } from '@/types/product';

type PageLink = { url: string | null; label: string; active: boolean };
const props = defineProps<{
    products: {
        data: Product[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string };
}>();
const search = ref(props.filters.search);
const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});
function filter() {
    router.get(
        '/products',
        { search: search.value },
        { preserveState: true, replace: true },
    );
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'Produtos', href: '/products' }] },
});
</script>

<template>
    <Head title="Produtos" />
    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Produtos</h1>
                <p class="text-sm text-muted-foreground">
                    Gerencie os produtos da barbearia.
                </p>
            </div>
            <Button as-child
                ><Link href="/products/create"
                    ><Plus aria-hidden="true" />Adicionar produto</Link
                ></Button
            >
        </div>
        <form class="flex max-w-md gap-2" @submit.prevent="filter">
            <Input
                v-model="search"
                aria-label="Buscar produtos"
                placeholder="Buscar por nome ou marca"
                maxlength="100"
            />
            <Button type="submit"><Search aria-hidden="true" />Buscar</Button>
        </form>
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted text-muted-foreground">
                    <tr>
                        <th class="p-4">Nome</th>
                        <th class="p-4">Marca</th>
                        <th class="p-4">Preço</th>
                        <th class="p-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in products.data"
                        :key="product.id"
                        class="border-t"
                    >
                        <td class="p-4 font-medium">{{ product.name }}</td>
                        <td class="p-4">{{ product.brand }}</td>
                        <td class="p-4">
                            {{ currency.format(Number(product.price)) }}
                        </td>
                        <td class="space-x-4 p-4">
                            <Link
                                class="inline-flex items-center gap-1 text-primary underline"
                                :href="`/products/${product.id}`"
                                ><Eye
                                    aria-hidden="true"
                                    class="size-4"
                                />Ver</Link
                            >
                            <Link
                                class="inline-flex items-center gap-1 text-primary underline"
                                :href="`/products/${product.id}/edit`"
                                ><EditPencil
                                    aria-hidden="true"
                                    class="size-4"
                                />Editar</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="!products.data.length">
                        <td colspan="4" class="p-4 text-muted-foreground">
                            Nenhum produto encontrado.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
            <p v-if="products.total">
                Exibindo {{ products.from }} a {{ products.to }} de
                {{ products.total }}
            </p>
            <p v-else>0 resultados</p>
            <nav aria-label="Paginação" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in products.links" :key="index">
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
                                : index === products.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}
                    </Link>
                    <span
                        v-else
                        class="rounded border px-3 py-1 text-muted-foreground"
                        >{{
                            index === 0
                                ? 'Anterior'
                                : index === products.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}</span
                    >
                </template>
            </nav>
        </div>
    </div>
</template>

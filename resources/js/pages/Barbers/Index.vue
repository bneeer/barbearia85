<script setup lang="ts">
import { EditPencil, Eye, Search, UserPlus } from '@iconoir/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Barber = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    avatar: string | null;
    barbershops: { id: number; name: string }[];
};
type PageLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    barbers: {
        data: Barber[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string };
}>();

const search = ref(props.filters.search);
function filter() {
    router.get(
        '/barbers',
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Barbeiros', href: '/barbers' }] },
});
</script>

<template>
    <Head title="Barbeiros" />
    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Barbeiros</h1>
                <p class="text-sm text-muted-foreground">
                    Gerencie os profissionais da barbearia.
                </p>
            </div>
            <Button as-child
                ><Link href="/barbers/create"
                    ><UserPlus aria-hidden="true" />Adicionar barbeiro</Link
                ></Button
            >
        </div>
        <form class="flex max-w-md gap-2" @submit.prevent="filter">
            <Input
                v-model="search"
                aria-label="Buscar barbeiros"
                placeholder="Buscar por nome ou e-mail"
            />
            <Button type="submit"><Search aria-hidden="true" />Buscar</Button>
        </form>
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted text-muted-foreground">
                    <tr>
                        <th class="p-4">Foto</th>
                        <th class="p-4">Nome</th>
                        <th class="p-4">E-mail</th>
                        <th class="p-4">Telefone</th>
                        <th class="p-4">Unidades</th>
                        <th class="p-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="barber in barbers.data"
                        :key="barber.id"
                        class="border-t"
                    >
                        <td class="p-4">
                            <img
                                v-if="barber.avatar"
                                :src="barber.avatar"
                                :alt="`Foto de ${barber.name}`"
                                class="size-10 rounded-full object-cover"
                            />
                            <span v-else class="text-muted-foreground"
                                >Sem foto</span
                            >
                        </td>
                        <td class="p-4 font-medium">{{ barber.name }}</td>
                        <td class="p-4">{{ barber.email }}</td>
                        <td class="p-4">
                            {{ barber.phone || 'Não informado' }}
                        </td>
                        <td class="p-4">
                            {{
                                barber.barbershops
                                    .map((barbershop) => barbershop.name)
                                    .join(', ') || 'Nenhuma'
                            }}
                        </td>
                        <td class="space-x-4 p-4">
                            <Link
                                class="inline-flex items-center gap-1 text-primary underline"
                                :href="`/barbers/${barber.id}`"
                                ><Eye
                                    aria-hidden="true"
                                    class="size-4"
                                />Ver</Link
                            ><Link
                                class="inline-flex items-center gap-1 text-primary underline"
                                :href="`/barbers/${barber.id}/edit`"
                                ><EditPencil
                                    aria-hidden="true"
                                    class="size-4"
                                />Editar</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="!barbers.data.length">
                        <td colspan="6" class="p-4 text-muted-foreground">
                            Nenhum barbeiro encontrado.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
            <p v-if="barbers.total">
                Exibindo {{ barbers.from }} a {{ barbers.to }} de
                {{ barbers.total }}
            </p>
            <p v-else>0 resultados</p>
            <nav aria-label="Paginação" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in barbers.links" :key="index">
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
                                : index === barbers.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}
                    </Link>
                    <span
                        v-else
                        class="rounded border px-3 py-1 text-muted-foreground"
                    >
                        {{
                            index === 0
                                ? 'Anterior'
                                : index === barbers.links.length - 1
                                  ? 'Próxima'
                                  : link.label
                        }}
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>

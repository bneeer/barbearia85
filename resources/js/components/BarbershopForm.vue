<script setup lang="ts">
import { ArrowLeft, FloppyDisk, Plus, Trash } from '@iconoir/vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    Barbershop,
    BarbershopBarber,
    OpeningHours,
} from '@/types/barbershop';

const props = defineProps<{
    barbershop?: Barbershop;
    barbers: BarbershopBarber[];
    barberIds: number[];
    defaultHours: OpeningHours;
    days: Record<string, string>;
}>();

const pageUrl = usePage().url;
const requestedTab = new URLSearchParams(pageUrl.split('?')[1] ?? '').get(
    'tab',
);
const activeTab = ref(
    ['basic', 'hours', 'barbers', 'photos'].includes(requestedTab ?? '')
        ? requestedTab!
        : 'basic',
);
const photoInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    name: props.barbershop?.name ?? '',
    description: props.barbershop?.description ?? '',
    address: props.barbershop?.address ?? '',
    zip_code: props.barbershop?.zip_code
        ? `${props.barbershop.zip_code.slice(0, 5)}-${props.barbershop.zip_code.slice(5)}`
        : '',
    phones: props.barbershop?.phones?.length
        ? [...props.barbershop.phones]
        : [''],
    is_open: props.barbershop?.is_open ?? true,
    opening_hours: props.barbershop?.opening_hours ?? props.defaultHours,
    barber_ids: props.barberIds,
});
const photosForm = useForm({ photos: [] as File[] });

function openingHourError(
    day: keyof OpeningHours,
    field: 'open' | 'close',
): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return errors[`opening_hours.${day}.${field}`];
}

function phoneError(index: number): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return errors[`phones.${index}`];
}

function addPhone() {
    form.phones.push('');
}

function removePhone(index: number) {
    form.phones.splice(index, 1);

    if (!form.phones.length) {
        form.phones.push('');
    }
}

function submit() {
    form.transform((data) => ({
        ...data,
        phones: data.phones.map((phone) => phone.trim()).filter(Boolean),
    }));

    const options = {
        onError: (errors: Record<string, string>) => {
            if (
                Object.keys(errors).some(
                    (key) =>
                        key === 'is_open' || key.startsWith('opening_hours'),
                )
            ) {
                activeTab.value = 'hours';
            } else if (
                Object.keys(errors).some(
                    (key) =>
                        key === 'barber_ids' || key.startsWith('barber_ids.'),
                )
            ) {
                activeTab.value = 'barbers';
            } else {
                activeTab.value = 'basic';
            }
        },
    };

    if (props.barbershop) {
        form.put(`/barbershops/${props.barbershop.id}`, options);
    } else {
        form.post('/barbershops', options);
    }
}

function selectPhotos(event: Event) {
    const input = event.target as HTMLInputElement;
    photoInput.value = input;
    photosForm.photos = Array.from(input.files ?? []);
}

function uploadPhotos() {
    if (!props.barbershop) {
        return;
    }

    photosForm.post(`/barbershops/${props.barbershop.id}/photos`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            photosForm.reset();

            if (photoInput.value) {
                photoInput.value.value = '';
            }
        },
    });
}

function removePhoto(photoId: number) {
    if (
        props.barbershop &&
        confirm('Deseja remover esta foto da galeria da unidade?')
    ) {
        router.delete(`/barbershops/${props.barbershop.id}/photos/${photoId}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <form class="space-y-5" novalidate @submit.prevent="submit">
        <TabsRoot v-model="activeTab" class="space-y-5">
            <TabsList
                aria-label="Seções do formulário da unidade"
                class="flex flex-wrap gap-1 rounded-lg bg-muted p-1"
            >
                <TabsTrigger
                    value="basic"
                    class="rounded-md px-3 py-2 text-sm data-[state=active]:bg-background data-[state=active]:shadow"
                >
                    Informações básicas
                </TabsTrigger>
                <TabsTrigger
                    value="hours"
                    class="rounded-md px-3 py-2 text-sm data-[state=active]:bg-background data-[state=active]:shadow"
                >
                    Funcionamento
                </TabsTrigger>
                <TabsTrigger
                    value="barbers"
                    class="rounded-md px-3 py-2 text-sm data-[state=active]:bg-background data-[state=active]:shadow"
                >
                    Barbeiros
                </TabsTrigger>
                <TabsTrigger
                    value="photos"
                    class="rounded-md px-3 py-2 text-sm data-[state=active]:bg-background data-[state=active]:shadow"
                >
                    Fotos
                </TabsTrigger>
            </TabsList>

            <TabsContent value="basic" class="space-y-5">
                <div class="grid gap-2">
                    <Label for="name">Nome</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        maxlength="255"
                        required
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="description">Descrição</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        required
                        maxlength="10000"
                        rows="4"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <div class="grid gap-2">
                    <Label for="address">Endereço (linha principal)</Label>
                    <Input
                        id="address"
                        v-model="form.address"
                        maxlength="255"
                        required
                        autocomplete="street-address"
                    />
                    <InputError :message="form.errors.address" />
                </div>
                <div class="grid gap-2">
                    <Label for="zip_code">CEP</Label>
                    <Input
                        id="zip_code"
                        v-model="form.zip_code"
                        inputmode="numeric"
                        maxlength="9"
                        placeholder="00000-000"
                        pattern="\d{5}-?\d{3}"
                        required
                    />
                    <InputError :message="form.errors.zip_code" />
                </div>
                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium">Telefones</legend>
                    <div
                        v-for="(phone, index) in form.phones"
                        :key="index"
                        class="flex items-start gap-2"
                    >
                        <div class="grid flex-1 gap-2">
                            <Label :for="`phone-${index}`">
                                Telefone {{ index + 1 }}
                            </Label>
                            <Input
                                :id="`phone-${index}`"
                                v-model="form.phones[index]"
                                type="tel"
                                maxlength="30"
                                autocomplete="tel"
                                placeholder="(11) 99999-9999"
                            />
                            <InputError :message="phoneError(index)" />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :aria-label="`Remover telefone ${index + 1}`"
                            class="mt-7"
                            @click="removePhone(index)"
                        >
                            <Trash aria-hidden="true" />
                        </Button>
                    </div>
                    <InputError :message="form.errors.phones" />
                    <Button type="button" variant="outline" @click="addPhone">
                        <Plus aria-hidden="true" />Adicionar telefone
                    </Button>
                </fieldset>
            </TabsContent>

            <TabsContent value="hours" class="space-y-5">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">
                        Estado da unidade
                    </legend>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_open" type="checkbox" />
                        Aberta
                    </label>
                    <p class="text-sm text-muted-foreground">
                        Este estado geral tem prioridade sobre o horário
                        semanal.
                    </p>
                    <InputError :message="form.errors.is_open" />
                </fieldset>
                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium">
                        Horário de funcionamento
                    </legend>
                    <p class="text-sm text-muted-foreground">
                        Marque os dias fechados e informe os horários dos demais
                        dias.
                    </p>
                    <div class="space-y-3">
                        <div
                            v-for="(label, day) in days"
                            :key="day"
                            class="grid gap-3 rounded-md border p-3 sm:grid-cols-[minmax(8rem,1fr)_auto_1fr_1fr] sm:items-end"
                        >
                            <Label :for="`${day}-open`">{{ label }}</Label>
                            <label class="flex items-center gap-2 pb-2 text-sm">
                                <input
                                    v-model="
                                        form.opening_hours[
                                            day as keyof OpeningHours
                                        ].closed
                                    "
                                    type="checkbox"
                                />
                                Fechado
                            </label>
                            <div class="grid gap-1">
                                <Label :for="`${day}-open`">Abertura</Label>
                                <Input
                                    :id="`${day}-open`"
                                    v-model="
                                        form.opening_hours[
                                            day as keyof OpeningHours
                                        ].open
                                    "
                                    type="time"
                                    required
                                    :disabled="
                                        form.opening_hours[
                                            day as keyof OpeningHours
                                        ].closed
                                    "
                                />
                                <InputError
                                    :message="
                                        openingHourError(
                                            day as keyof OpeningHours,
                                            'open',
                                        )
                                    "
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`${day}-close`">Fechamento</Label>
                                <Input
                                    :id="`${day}-close`"
                                    v-model="
                                        form.opening_hours[
                                            day as keyof OpeningHours
                                        ].close
                                    "
                                    type="time"
                                    required
                                    :disabled="
                                        form.opening_hours[
                                            day as keyof OpeningHours
                                        ].closed
                                    "
                                />
                                <InputError
                                    :message="
                                        openingHourError(
                                            day as keyof OpeningHours,
                                            'close',
                                        )
                                    "
                                />
                            </div>
                        </div>
                    </div>
                    <InputError :message="form.errors.opening_hours" />
                </fieldset>
            </TabsContent>

            <TabsContent value="barbers" class="space-y-3">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">
                        Barbeiros que atendem nesta unidade
                    </legend>
                    <p
                        v-if="!barbers.length"
                        class="text-sm text-muted-foreground"
                    >
                        Nenhum barbeiro cadastrado.
                    </p>
                    <div
                        v-else
                        class="max-h-96 space-y-2 overflow-y-auto rounded-md border p-3"
                    >
                        <label
                            v-for="barber in barbers"
                            :key="barber.id"
                            class="flex cursor-pointer items-center gap-3 rounded-md p-2 text-sm hover:bg-muted"
                        >
                            <input
                                v-model="form.barber_ids"
                                type="checkbox"
                                :value="barber.id"
                            />
                            <Avatar class="size-10">
                                <AvatarImage
                                    v-if="barber.avatar"
                                    :src="barber.avatar"
                                    :alt="`Foto de ${barber.name}`"
                                />
                                <AvatarFallback>
                                    {{ barber.name.slice(0, 2).toUpperCase() }}
                                </AvatarFallback>
                            </Avatar>
                            <span class="grid">
                                <span class="font-medium">{{
                                    barber.name
                                }}</span>
                                <span class="text-muted-foreground">{{
                                    barber.email
                                }}</span>
                            </span>
                        </label>
                    </div>
                    <InputError :message="form.errors.barber_ids" />
                    <InputError
                        v-for="(message, key) in form.errors"
                        :key="key"
                        :message="
                            String(key).startsWith('barber_ids.')
                                ? message
                                : undefined
                        "
                    />
                </fieldset>
            </TabsContent>

            <TabsContent value="photos" class="space-y-5">
                <section class="space-y-3">
                    <div>
                        <h2 class="text-sm font-medium">Fotos da unidade</h2>
                        <p class="text-sm text-muted-foreground">
                            As URLs desta galeria ficam públicas para integração
                            com o fluxo de agendamento.
                        </p>
                    </div>
                    <template v-if="barbershop">
                        <div
                            v-if="barbershop.photos?.length"
                            class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                        >
                            <div
                                v-for="photo in barbershop.photos"
                                :key="photo.id"
                                class="group relative"
                            >
                                <img
                                    :src="photo.url"
                                    alt="Foto da unidade"
                                    class="aspect-square w-full rounded-md border object-cover"
                                />
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="icon"
                                    :aria-label="'Remover foto da unidade'"
                                    class="absolute top-2 right-2 opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                                    @click="removePhoto(photo.id)"
                                >
                                    <Trash aria-hidden="true" />
                                </Button>
                            </div>
                        </div>
                        <p
                            v-else
                            class="rounded-md border p-4 text-sm text-muted-foreground"
                        >
                            Nenhuma foto adicionada.
                        </p>
                        <div class="grid gap-3">
                            <Label for="unit-photos">Adicionar fotos</Label>
                            <Input
                                id="unit-photos"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                                @change="selectPhotos"
                            />
                            <p class="text-sm text-muted-foreground">
                                JPEG, PNG ou WebP, até 5 MB por imagem. Não há
                                limite de quantidade.
                            </p>
                            <InputError :message="photosForm.errors.photos" />
                            <InputError
                                v-for="(message, key) in photosForm.errors"
                                :key="key"
                                :message="
                                    String(key).startsWith('photos.')
                                        ? message
                                        : undefined
                                "
                            />
                            <Button
                                type="button"
                                class="w-fit"
                                :disabled="
                                    photosForm.processing ||
                                    !photosForm.photos.length
                                "
                                @click="uploadPhotos"
                            >
                                <Plus aria-hidden="true" />Enviar fotos
                            </Button>
                        </div>
                    </template>
                    <p
                        v-else
                        class="rounded-md border p-4 text-sm text-muted-foreground"
                    >
                        Salve a unidade primeiro para poder carregar fotos.
                    </p>
                </section>
            </TabsContent>
        </TabsRoot>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">
                <FloppyDisk aria-hidden="true" />Salvar unidade
            </Button>
            <Button variant="outline" as-child>
                <Link href="/barbershops">
                    <ArrowLeft aria-hidden="true" />Voltar
                </Link>
            </Button>
        </div>
    </form>
</template>

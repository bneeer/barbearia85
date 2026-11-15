<script setup lang="ts">
import { ArrowLeft, FloppyDisk } from '@iconoir/vue';
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Barber, BarberService } from '@/types/barber';

type BarberShopOption = { id: number; name: string };
const props = defineProps<{
    barber?: Barber;
    services: BarberService[];
    barbershops: BarberShopOption[];
    defaultBarbershopId: number;
}>();
const initialSalary = Object.fromEntries(
    (props.barber?.barbershops ?? []).map((shop) => [
        String(shop.id),
        ((shop.pivot?.salary_base_cents ?? 0) / 100).toFixed(2),
    ]),
);
const form = useForm({
    name: props.barber?.name ?? '',
    email: props.barber?.email ?? '',
    phone: props.barber?.phone ?? '',
    password: '',
    photo: null as File | null,
    service_ids: props.barber?.services.map((service) => service.id) ?? [],
    barbershop_ids: props.barber
        ? props.barber.barbershops.length
            ? props.barber.barbershops.map((barbershop) => barbershop.id)
            : props.barber.barbershop_id
              ? [props.barber.barbershop_id]
              : []
        : [props.defaultBarbershopId],
    salary_base: initialSalary as Record<string, string>,
});
watch(
    () => form.barbershop_ids,
    (ids) => {
        for (const id of ids) {
            form.salary_base[String(id)] ??= '0.00';
        }
    },
    { deep: true, immediate: true },
);

function selectPhoto(event: Event) {
    const input = event.target as HTMLInputElement;
    form.photo = input.files?.[0] ?? null;
}

function submit() {
    if (props.barber) {
        form.transform((data) => ({
            name: data.name,
            email: data.email,
            phone: data.phone,
            photo: data.photo,
            service_ids: data.service_ids,
            barbershop_ids: data.barbershop_ids,
            salary_base: data.salary_base,
            _method: 'PUT',
        })).post(`/barbers/${props.barber.id}`, { forceFormData: true });
    } else {
        form.post('/barbers', { forceFormData: true });
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
            <Label for="email">E-mail</Label>
            <Input
                id="email"
                v-model="form.email"
                type="email"
                maxlength="255"
                required
            />
            <InputError :message="form.errors.email" />
        </div>
        <div v-if="!barber" class="grid gap-2">
            <Label for="password">Senha</Label>
            <Input
                id="password"
                v-model="form.password"
                type="password"
                minlength="6"
                required
            />
            <InputError :message="form.errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="phone">Telefone</Label>
            <PhoneInput id="phone" v-model="form.phone" />
            <InputError :message="form.errors.phone" />
        </div>
        <div class="grid gap-2">
            <Label for="photo">Foto</Label>
            <img
                v-if="barber?.avatar"
                :src="barber.avatar"
                :alt="`Foto de ${barber.name}`"
                class="size-20 rounded-full object-cover"
            />
            <p v-else class="text-sm text-muted-foreground">
                Nenhuma foto cadastrada.
            </p>
            <input
                id="photo"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="block w-full rounded-md border border-input p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-primary file:px-3 file:py-1 file:text-primary-foreground"
                @change="selectPhoto"
            />
            <p class="text-sm text-muted-foreground">
                JPEG, PNG ou WebP, até 2 MB. A foto também aparece no perfil do
                barbeiro.
            </p>
            <InputError :message="form.errors.photo" />
        </div>
        <fieldset class="space-y-2">
            <legend class="text-sm font-medium">
                Serviços que pode prestar
            </legend>
            <p v-if="!services.length" class="text-sm text-muted-foreground">
                Nenhum serviço cadastrado.
            </p>
            <div
                v-else
                class="max-h-60 space-y-2 overflow-y-auto rounded-md border p-3"
            >
                <label
                    v-for="service in services"
                    :key="service.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="form.service_ids"
                        type="checkbox"
                        :value="service.id"
                    />
                    {{ service.name
                    }}{{ service.is_active ? '' : ' (inativo)' }}
                </label>
            </div>
            <InputError :message="form.errors.service_ids" />
            <InputError
                v-for="(message, key) in form.errors"
                :key="key"
                :message="
                    String(key).startsWith('service_ids.') ? message : undefined
                "
            />
        </fieldset>
        <fieldset class="space-y-2">
            <legend class="text-sm font-medium">Unidades em que atende</legend>
            <div
                v-if="barbershops.length"
                class="max-h-60 space-y-2 overflow-y-auto rounded-md border p-3"
            >
                <label
                    v-for="barbershop in barbershops"
                    :key="barbershop.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="form.barbershop_ids"
                        type="checkbox"
                        :value="barbershop.id"
                    />
                    {{ barbershop.name }}
                </label>
                <div
                    v-for="barbershop in barbershops.filter((item) =>
                        form.barbershop_ids.includes(item.id),
                    )"
                    :key="`salary-${barbershop.id}`"
                    class="grid gap-1 pl-6"
                >
                    <Label :for="`salary-${barbershop.id}`">
                        Salário base mensal em {{ barbershop.name }} (R$)
                    </Label>
                    <Input
                        :id="`salary-${barbershop.id}`"
                        v-model="form.salary_base[String(barbershop.id)]"
                        type="number"
                        min="0"
                        max="99999999.99"
                        step="0.01"
                        required
                    />
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Nenhuma unidade cadastrada.
            </p>
            <InputError :message="form.errors.barbershop_ids" />
            <InputError :message="form.errors.salary_base" />
            <InputError
                v-for="(message, key) in form.errors"
                :key="key"
                :message="
                    String(key).startsWith('salary_base.') ? message : undefined
                "
            />
            <InputError
                v-for="(message, key) in form.errors"
                :key="key"
                :message="
                    String(key).startsWith('barbershop_ids.')
                        ? message
                        : undefined
                "
            />
        </fieldset>
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing"
                ><FloppyDisk aria-hidden="true" />Salvar</Button
            >
            <Button variant="outline" as-child
                ><Link href="/barbers"
                    ><ArrowLeft aria-hidden="true" />Voltar</Link
                ></Button
            >
        </div>
    </form>
</template>

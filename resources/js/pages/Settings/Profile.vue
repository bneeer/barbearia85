<script setup lang="ts">
import { Camera, FloppyDisk } from '@iconoir/vue';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Perfil" />

    <h1 class="sr-only">Perfil</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Informações do perfil"
            description="Atualize seus dados e sua foto"
        />

        <Form
            action="/settings/profile"
            method="post"
            enctype="multipart/form-data"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="_method" value="PATCH" />
            <div
                v-if="user.role === 'barber' || user.role === 'admin'"
                class="grid gap-2"
            >
                <Label for="photo"
                    ><Camera aria-hidden="true" class="size-4" />Foto do
                    perfil</Label
                >
                <img
                    v-if="user.avatar"
                    :src="user.avatar"
                    :alt="`Foto de ${user.name}`"
                    class="size-20 rounded-full object-cover"
                />
                <input
                    id="photo"
                    type="file"
                    name="photo"
                    accept="image/jpeg,image/png,image/webp"
                    class="block w-full rounded-md border border-input p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-primary file:px-3 file:py-1 file:text-primary-foreground"
                />
                <p class="text-sm text-muted-foreground">
                    JPEG, PNG ou WebP, até 2 MB.
                </p>
                <InputError :message="errors.photo" />
            </div>
            <div class="grid gap-2">
                <Label for="name">Nome</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Nome completo"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">E-mail</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="E-mail"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Seu e-mail ainda não foi verificado.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Clique aqui para reenviar o e-mail de verificação.
                    </Link>
                </p>

                <div
                    v-if="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    Um novo link de verificação foi enviado para seu e-mail.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    ><FloppyDisk aria-hidden="true" />Salvar</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>

<script setup lang="ts">
import { ArrowRight, Calendar, LogIn } from '@iconoir/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useIntervalFn, usePreferredReducedMotion } from '@vueuse/core';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Toaster } from '@/components/ui/sonner';
import { booking, dashboard, login } from '@/routes';

const props = defineProps<{ photos: { url: string }[] }>();
const slides = computed(() =>
    props.photos.length ? props.photos : [{ url: '/img/barbearia85.jpg' }],
);
const activeSlide = ref(0);
const reducedMotion = usePreferredReducedMotion();
const showCancellation = ref(false);
const cancellationForm = useForm({ email: '' });
const portalForm = useForm({ email: '' });
const showPortal = ref(false);

function requestCancellation() {
    cancellationForm.post('/cancelar-agendamento', {
        preserveScroll: true,
        onSuccess: () => cancellationForm.reset(),
    });
}

function requestPortal() {
    portalForm.post('/area-do-cliente', {
        preserveScroll: true,
        onSuccess: () => {
            portalForm.reset();
            showPortal.value = false;
        },
    });
}

useIntervalFn(() => {
    if (reducedMotion.value !== 'reduce' && slides.value.length > 1) {
        activeSlide.value = (activeSlide.value + 1) % slides.value.length;
    }
}, 6000);
</script>

<template>
    <Toaster />
    <Head title="A tradição do corte, a modernidade do estilo.">
        <meta
            name="description"
            content="Corte impecável, barba alinhada e um momento só seu. Viva a experiência Barbearia 85 e agende seu horário."
        />
    </Head>

    <div class="landing-page relative isolate bg-background text-foreground">
        <div class="absolute inset-0 -z-20 overflow-hidden" aria-hidden="true">
            <img
                v-for="(photo, index) in slides"
                :key="photo.url"
                :src="photo.url"
                alt=""
                class="landing-slide absolute inset-0 h-full w-full object-cover"
                :class="index === activeSlide ? 'opacity-100' : 'opacity-0'"
                :fetchpriority="index === 0 ? 'high' : 'auto'"
            />
        </div>
        <div
            class="landing-overlay absolute inset-0 -z-10"
            aria-hidden="true"
        />

        <header
            class="absolute inset-x-0 top-0 z-10 flex justify-end px-5 py-5 sm:px-10 sm:py-7"
        >
            <Link
                :href="$page.props.auth.user ? dashboard() : login()"
                class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-black/10 px-4 py-2.5 text-sm font-medium text-white backdrop-blur-sm transition-colors hover:border-primary hover:bg-primary/10 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
            >
                <LogIn class="size-4" aria-hidden="true" />
                Área do Barbeiro
            </Link>
        </header>

        <main
            class="landing-content flex h-full overflow-y-auto px-6 text-center"
        >
            <div
                class="m-auto flex w-full max-w-3xl flex-col items-center py-24"
            >
                <img
                    src="/img/Barbearia85.png"
                    alt="Barbearia 85"
                    class="landing-logo mb-6 rounded-full object-contain shadow-2xl sm:mb-8"
                    width="1024"
                    height="1024"
                />
                <p
                    class="mb-4 text-xs font-semibold tracking-[0.3em] text-primary uppercase sm:text-sm"
                >
                    Mais que um corte. Uma experiência.
                </p>
                <h1
                    class="text-4xl leading-tight font-bold tracking-tight text-white sm:text-6xl lg:text-7xl"
                >
                    A tradição do corte,<br />
                    <span class="text-primary">a modernidade do estilo.</span>
                </h1>
                <p
                    class="mt-5 max-w-xl text-base leading-relaxed text-white/80 sm:mt-6 sm:text-lg"
                >
                    Corte impecável, barba alinhada e um momento só seu. Na
                    Barbearia 85, cada detalhe é pensado para você sair com
                    confiança e personalidade.
                </p>
                <Link
                    :href="booking()"
                    class="mt-8 inline-flex items-center justify-center gap-3 rounded-full bg-primary px-8 py-4 text-base font-bold text-primary-foreground shadow-lg shadow-black/30 transition duration-300 hover:-translate-y-0.5 hover:bg-primary/90 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary motion-reduce:transform-none sm:mt-10"
                >
                    <Calendar class="size-5" aria-hidden="true" />
                    Agendar Horário
                    <ArrowRight class="size-5" aria-hidden="true" />
                </Link>
                <button
                    type="button"
                    class="mt-4 rounded px-4 py-2 text-sm text-white underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-primary"
                    :aria-expanded="showCancellation"
                    aria-controls="cancellation-request"
                    @click="showCancellation = !showCancellation"
                >
                    Cancelar um agendamento
                </button>
                <Dialog v-model:open="showPortal">
                    <DialogTrigger as-child>
                        <button
                            type="button"
                            class="rounded px-4 py-2 text-sm text-white underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-primary"
                        >
                            Consultar meus agendamentos
                        </button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Consultar agendamentos</DialogTitle>
                            <DialogDescription>
                                Informe o e-mail usado nos agendamentos. O link
                                de acesso é válido por uma hora.
                            </DialogDescription>
                        </DialogHeader>
                        <form class="space-y-4" @submit.prevent="requestPortal">
                            <Label for="portal-email">E-mail</Label>
                            <Input
                                id="portal-email"
                                v-model="portalForm.email"
                                type="email"
                                autocomplete="email"
                                maxlength="255"
                                required
                                :aria-invalid="!!portalForm.errors.email"
                                aria-describedby="portal-email-error"
                            />
                            <InputError
                                id="portal-email-error"
                                :message="portalForm.errors.email"
                            />
                            <Button
                                type="submit"
                                class="w-full"
                                :disabled="portalForm.processing"
                            >
                                {{
                                    portalForm.processing
                                        ? 'Solicitando...'
                                        : 'Enviar link de acesso'
                                }}
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
                <form
                    v-if="showCancellation"
                    id="cancellation-request"
                    class="mt-4 w-full max-w-md space-y-3 rounded-xl border border-white/25 bg-background p-5 text-left"
                    @submit.prevent="requestCancellation"
                >
                    <Label for="cancellation-email"
                        >E-mail usado no agendamento</Label
                    >
                    <Input
                        id="cancellation-email"
                        v-model="cancellationForm.email"
                        type="email"
                        autocomplete="email"
                        maxlength="255"
                        required
                        :aria-invalid="!!cancellationForm.errors.email"
                        aria-describedby="cancellation-email-error"
                    />
                    <InputError
                        id="cancellation-email-error"
                        :message="cancellationForm.errors.email"
                    />
                    <p class="text-sm text-muted-foreground">
                        Enviaremos as informações para você confirmar o
                        cancelamento por e-mail.
                    </p>
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="cancellationForm.processing"
                    >
                        {{
                            cancellationForm.processing
                                ? 'Solicitando...'
                                : 'Solicitar cancelamento'
                        }}
                    </Button>
                </form>
            </div>
        </main>
    </div>
</template>

<style scoped>
.landing-page {
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
}

.landing-slide {
    filter: grayscale(100%);
    transition: opacity 1.5s ease-in-out;
}

.landing-overlay {
    background-color: rgba(0, 0, 0, 0.7);
}

.landing-logo {
    width: clamp(7rem, 20vh, 12rem);
    height: clamp(7rem, 20vh, 12rem);
}

@media (prefers-reduced-motion: reduce) {
    .landing-slide {
        transition: none;
    }
}
</style>

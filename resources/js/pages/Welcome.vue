<script setup lang="ts">
import { ArrowRight, Calendar, LogIn } from '@iconoir/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    useIntervalFn,
    usePreferredReducedMotion,
    useWindowScroll,
} from '@vueuse/core';
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
const { y: scrollY } = useWindowScroll();
const heroParallaxStyle = computed(() => {
    const offset =
        reducedMotion.value === 'reduce'
            ? 0
            : Math.min(scrollY.value * 0.12, 72);

    return { transform: `translate3d(0, ${offset}px, 0) scale(1.12)` };
});
const featureParallaxStyle = computed(() => {
    const offset =
        reducedMotion.value === 'reduce'
            ? 0
            : Math.min(Math.max((scrollY.value - 480) * 0.07, 0), 54);

    return { transform: `translate3d(0, ${offset}px, 0) scale(1.08)` };
});
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
            content="Corte e barba com atenção ao seu estilo. Conheça a Barbearia 85 e agende seu horário."
        />
    </Head>

    <div class="landing-page bg-background text-foreground">
        <section
            class="landing-hero relative isolate flex min-h-[100svh] items-center justify-center overflow-hidden px-6 py-24 text-center"
        >
            <div
                class="absolute inset-0 -z-20 overflow-hidden"
                aria-hidden="true"
            >
                <img
                    v-for="(photo, index) in slides"
                    :key="photo.url"
                    :src="photo.url"
                    alt=""
                    class="landing-slide absolute inset-x-0"
                    :class="index === activeSlide ? 'opacity-100' : 'opacity-0'"
                    :fetchpriority="index === 0 ? 'high' : 'auto'"
                    :style="heroParallaxStyle"
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

            <div class="flex w-full max-w-4xl flex-col items-center py-12">
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
                    class="mt-5 max-w-2xl text-base leading-relaxed text-white/80 sm:mt-6 sm:text-lg"
                >
                    Seu visual tem a sua identidade. Conte o que você procura,
                    escolha seu horário e venha cuidar de cada detalhe com a
                    Barbearia 85.
                </p>
                <div
                    class="mt-8 flex w-full flex-col items-center gap-3 sm:mt-10 sm:w-auto sm:flex-row"
                >
                    <Link
                        :href="booking()"
                        class="inline-flex w-full items-center justify-center gap-3 rounded-full bg-primary px-8 py-4 text-base font-bold text-primary-foreground shadow-lg shadow-black/30 transition duration-300 hover:-translate-y-0.5 hover:bg-primary/90 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary motion-reduce:transform-none sm:w-auto"
                    >
                        <Calendar class="size-5" aria-hidden="true" />
                        Escolher horário
                        <ArrowRight class="size-5" aria-hidden="true" />
                    </Link>
                    <a
                        href="#servicos"
                        class="inline-flex items-center justify-center rounded-full border border-white/35 px-7 py-4 text-sm font-semibold text-white transition-colors hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                    >
                        Conhecer a experiência
                    </a>
                </div>
                <a
                    href="#experiencia"
                    class="mt-10 text-sm text-white/65 underline decoration-white/35 underline-offset-4 transition-colors hover:text-white"
                >
                    Descubra a Barbearia 85
                </a>
            </div>
        </section>

        <main>
            <section
                id="experiencia"
                class="scroll-mt-8 bg-background px-6 py-20 sm:py-28"
            >
                <div
                    class="mx-auto grid max-w-6xl gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:gap-20"
                >
                    <div>
                        <p
                            class="text-xs font-bold tracking-[0.25em] text-primary uppercase sm:text-sm"
                        >
                            O seu estilo em primeiro lugar
                        </p>
                        <h2
                            class="mt-4 max-w-xl text-3xl leading-tight font-bold tracking-tight sm:text-5xl"
                        >
                            Um bom corte começa com
                            <span class="text-primary">uma boa conversa.</span>
                        </h2>
                        <p
                            class="mt-6 max-w-2xl text-base leading-relaxed text-muted-foreground sm:text-lg"
                        >
                            Cada pessoa tem um jeito e uma ideia do visual que
                            quer. Na Barbearia 85, você escolhe o que combina
                            com você e agenda com praticidade, para chegar ao
                            atendimento sabendo qual é o próximo passo.
                        </p>
                        <Link
                            :href="booking()"
                            class="mt-8 inline-flex items-center gap-2 font-semibold text-foreground transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                        >
                            Vamos marcar seu horário
                            <ArrowRight class="size-5" aria-hidden="true" />
                        </Link>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <article
                            class="rounded-2xl border border-border bg-card p-6 sm:p-7"
                        >
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >01</span
                            >
                            <h3 class="mt-5 text-lg font-bold">
                                Atenção ao seu pedido
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                O resultado que você imagina começa por entender
                                o que faz sentido para o seu visual.
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-border bg-card p-6 sm:mt-12 sm:p-7"
                        >
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >02</span
                            >
                            <h3 class="mt-5 text-lg font-bold">
                                Cabelo e barba
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Escolha os cuidados que procura e organize seu
                                atendimento em um só agendamento.
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-border bg-card p-6 sm:col-span-2 sm:p-7"
                        >
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >03</span
                            >
                            <h3 class="mt-5 text-lg font-bold">
                                Um próximo passo simples
                            </h3>
                            <p
                                class="mt-2 max-w-xl text-sm leading-relaxed text-muted-foreground"
                            >
                                Encontre uma unidade, escolha o serviço e
                                consulte os horários disponíveis pelo próprio
                                site.
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section
                id="servicos"
                class="scroll-mt-8 bg-[#11100e] px-6 py-20 text-white sm:py-28"
            >
                <div class="mx-auto max-w-6xl">
                    <div class="mx-auto max-w-2xl text-center">
                        <p
                            class="text-xs font-bold tracking-[0.25em] text-primary uppercase sm:text-sm"
                        >
                            Seu cuidado, do seu jeito
                        </p>
                        <h2
                            class="mt-4 text-3xl leading-tight font-bold tracking-tight sm:text-5xl"
                        >
                            O que você quer renovar hoje?
                        </h2>
                        <p class="mt-5 text-base leading-relaxed text-white/65">
                            Escolha o cuidado que combina com você. Consulte
                            serviços e disponibilidade ao iniciar o agendamento.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-5 md:grid-cols-3">
                        <article
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-7 transition-colors hover:border-primary/60 sm:p-8"
                        >
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >01 / CABELO</span
                            >
                            <h3 class="mt-5 text-2xl font-bold">
                                Corte masculino
                            </h3>
                            <p
                                class="mt-3 text-sm leading-relaxed text-white/65"
                            >
                                Um visual alinhado com o que você quer e com seu
                                estilo.
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-7 transition-colors hover:border-primary/60 sm:p-8"
                        >
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >02 / BARBA</span
                            >
                            <h3 class="mt-5 text-2xl font-bold">Barba</h3>
                            <p
                                class="mt-3 text-sm leading-relaxed text-white/65"
                            >
                                Um cuidado para completar o visual com atenção
                                aos detalhes.
                            </p>
                        </article>
                        <article
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-7 transition-colors hover:border-primary/60 sm:p-8"
                        >
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >03 / COMBO</span
                            >
                            <h3 class="mt-5 text-2xl font-bold">
                                Cabelo + barba
                            </h3>
                            <p
                                class="mt-3 text-sm leading-relaxed text-white/65"
                            >
                                Combine os cuidados e planeje seu atendimento
                                pelo agendamento online.
                            </p>
                        </article>
                    </div>

                    <div class="mt-10 text-center">
                        <Link
                            :href="booking()"
                            class="inline-flex items-center justify-center gap-3 rounded-full bg-primary px-7 py-3.5 font-bold text-primary-foreground transition hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                        >
                            Ver serviços e horários
                            <ArrowRight class="size-5" aria-hidden="true" />
                        </Link>
                    </div>
                </div>
            </section>

            <section
                class="landing-parallax-band relative isolate flex min-h-[20rem] items-center justify-center overflow-hidden px-6 py-20 text-center sm:min-h-[25rem]"
            >
                <div
                    class="absolute inset-0 -z-20 overflow-hidden"
                    aria-hidden="true"
                >
                    <img
                        :src="slides[0].url"
                        alt=""
                        class="landing-band-image absolute inset-x-0"
                        :style="featureParallaxStyle"
                    />
                </div>
                <div
                    class="landing-band-overlay absolute inset-0 -z-10"
                    aria-hidden="true"
                />
                <div class="max-w-3xl">
                    <p
                        class="text-xs font-bold tracking-[0.25em] text-primary uppercase sm:text-sm"
                    >
                        Barbearia 85
                    </p>
                    <h2
                        class="mt-4 text-3xl leading-tight font-bold tracking-tight text-white sm:text-5xl"
                    >
                        Seu próximo visual começa com uma escolha.
                    </h2>
                    <p class="mx-auto mt-5 max-w-xl text-white/75 sm:text-lg">
                        Escolha o que deseja fazer e encontre um horário para
                        cuidar de você.
                    </p>
                    <Link
                        :href="booking()"
                        class="mt-8 inline-flex items-center justify-center gap-3 rounded-full bg-primary px-7 py-3.5 font-bold text-primary-foreground transition hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                    >
                        Agendar meu horário
                        <ArrowRight class="size-5" aria-hidden="true" />
                    </Link>
                </div>
            </section>

            <section class="bg-background px-6 py-20 sm:py-28">
                <div class="mx-auto max-w-6xl">
                    <div class="mx-auto max-w-2xl text-center">
                        <p
                            class="text-xs font-bold tracking-[0.25em] text-primary uppercase sm:text-sm"
                        >
                            Sem complicação
                        </p>
                        <h2
                            class="mt-4 text-3xl leading-tight font-bold tracking-tight sm:text-5xl"
                        >
                            Marcar é fácil. O próximo passo é seu.
                        </h2>
                    </div>
                    <div class="mt-12 grid gap-8 md:grid-cols-3">
                        <article class="border-t border-border pt-6">
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >PASSO 01</span
                            >
                            <h3 class="mt-4 text-xl font-bold">
                                Conte o que procura
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Informe seus dados e escolha a unidade e os
                                serviços disponíveis.
                            </p>
                        </article>
                        <article class="border-t border-border pt-6">
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >PASSO 02</span
                            >
                            <h3 class="mt-4 text-xl font-bold">
                                Escolha seu horário
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Selecione um profissional ou consulte os
                                horários disponíveis da equipe.
                            </p>
                        </article>
                        <article class="border-t border-border pt-6">
                            <span
                                class="text-sm font-bold tracking-[0.2em] text-primary"
                                >PASSO 03</span
                            >
                            <h3 class="mt-4 text-xl font-bold">
                                Revise e confirme
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Confira os detalhes do pedido e confirme seu
                                agendamento pelo site.
                            </p>
                        </article>
                    </div>
                    <div class="mt-10 text-center">
                        <Link
                            :href="booking()"
                            class="inline-flex items-center justify-center gap-3 rounded-full bg-primary px-8 py-4 font-bold text-primary-foreground transition hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                        >
                            Começar meu agendamento
                            <ArrowRight class="size-5" aria-hidden="true" />
                        </Link>
                    </div>
                </div>
            </section>

            <section
                class="bg-[#11100e] px-6 py-20 text-center text-white sm:py-24"
            >
                <div class="mx-auto max-w-3xl">
                    <p
                        class="text-xs font-bold tracking-[0.25em] text-primary uppercase sm:text-sm"
                    >
                        Pronto para renovar?
                    </p>
                    <h2
                        class="mt-4 text-3xl leading-tight font-bold tracking-tight sm:text-5xl"
                    >
                        Reserve um momento para você.
                    </h2>
                    <p class="mt-5 text-white/70 sm:text-lg">
                        Consulte as unidades, serviços e horários disponíveis e
                        escolha o que funciona para você.
                    </p>
                    <Link
                        :href="booking()"
                        class="mt-8 inline-flex items-center justify-center gap-3 rounded-full bg-primary px-8 py-4 font-bold text-primary-foreground transition hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                    >
                        <Calendar class="size-5" aria-hidden="true" />
                        Agendar agora
                        <ArrowRight class="size-5" aria-hidden="true" />
                    </Link>

                    <div
                        class="mt-14 border-t border-white/15 pt-8 text-left sm:mt-16 sm:pt-10"
                    >
                        <h3 class="text-xl font-bold">
                            Já tem um agendamento?
                        </h3>
                        <p
                            class="mt-2 max-w-2xl text-sm leading-relaxed text-white/65"
                        >
                            Consulte seus horários ou solicite o cancelamento
                            usando o e-mail informado na reserva.
                        </p>
                        <div
                            class="mt-5 flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:gap-5"
                        >
                            <Dialog v-model:open="showPortal">
                                <DialogTrigger as-child>
                                    <button
                                        type="button"
                                        class="rounded px-1 py-2 text-sm font-medium text-white underline underline-offset-4 transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                    >
                                        Consultar meus agendamentos
                                    </button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Consultar agendamentos
                                        </DialogTitle>
                                        <DialogDescription>
                                            Informe o e-mail usado nos
                                            agendamentos. O link de acesso é
                                            válido por uma hora.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form
                                        class="space-y-4"
                                        @submit.prevent="requestPortal"
                                    >
                                        <Label for="portal-email">E-mail</Label>
                                        <Input
                                            id="portal-email"
                                            v-model="portalForm.email"
                                            type="email"
                                            autocomplete="email"
                                            maxlength="255"
                                            required
                                            :aria-invalid="
                                                !!portalForm.errors.email
                                            "
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
                            <button
                                type="button"
                                class="rounded px-1 py-2 text-sm font-medium text-white underline underline-offset-4 transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                :aria-expanded="showCancellation"
                                aria-controls="cancellation-request"
                                @click="showCancellation = !showCancellation"
                            >
                                Solicitar cancelamento
                            </button>
                        </div>
                        <form
                            v-if="showCancellation"
                            id="cancellation-request"
                            class="mt-4 w-full max-w-md space-y-3 rounded-xl border border-white/15 bg-background p-5 text-left text-foreground"
                            @submit.prevent="requestCancellation"
                        >
                            <Label for="cancellation-email">
                                E-mail usado no agendamento
                            </Label>
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
                </div>
            </section>
        </main>
    </div>
</template>

<style scoped>
.landing-hero,
.landing-parallax-band {
    background-color: #11100e;
}

.landing-slide {
    top: -8%;
    height: 116%;
    width: 100%;
    object-fit: cover;
    filter: grayscale(100%);
    transition: opacity 1.5s ease-in-out;
    will-change: transform;
}

.landing-overlay {
    background-color: rgba(0, 0, 0, 0.7);
}

.landing-logo {
    width: clamp(7rem, 20vh, 12rem);
    height: clamp(7rem, 20vh, 12rem);
}

.landing-band-image {
    top: -10%;
    height: 120%;
    width: 100%;
    object-fit: cover;
    filter: grayscale(100%);
    will-change: transform;
}

.landing-band-overlay {
    background-color: rgba(0, 0, 0, 0.72);
}

@media (prefers-reduced-motion: reduce) {
    .landing-slide {
        transition: none;
        will-change: auto;
    }

    .landing-band-image {
        will-change: auto;
    }
}
</style>

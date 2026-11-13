<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BookingGallery from '@/components/BookingGallery.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import PublicFooter from '@/components/PublicFooter.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { endTime, overlapsDraft, reconcileDrafts } from '@/lib/booking';
import type { BookingDraft } from '@/lib/booking';

type Service = {
    id: number;
    name: string;
    description: string;
    duration: number;
    price: string;
};
type Barber = {
    id: number;
    name: string;
    avatar: string | null;
    service_ids: number[];
};
type Shop = {
    id: number;
    name: string;
    description: string | null;
    address: string | null;
    phones: string[];
    photos: { url: string }[];
    timezone: string;
    today: string;
    barbers: Barber[];
    services: Service[];
};
type Slot = { time: string; barber_ids: number[] };
type ApiError = { message?: string; errors?: Record<string, string[]> };
type ConfirmedAppointment = {
    id: number;
    service: Pick<Service, 'id' | 'name' | 'duration' | 'price'>;
    barber: { id: number; name: string };
    starts_at: string;
    ends_at: string;
    timezone: string;
};

const props = defineProps<{ barbershops: Shop[]; csrfToken: string }>();
const labels = [
    'Seus dados',
    'Unidade',
    'Serviços',
    'Barbeiro',
    'Data e horário',
    'Confirmação',
];
const titles = [
    'Vamos nos conhecer?',
    'Onde será sua próxima experiência?',
    'Quais cuidados você procura?',
    'Com quem você quer agendar?',
    'Reserve um momento só seu.',
    'Tudo pronto para confirmar?',
];
const copies = [
    'Informe seus dados para identificar seu agendamento.',
    'Escolha uma unidade e conheça o espaço pelas fotos. Deslize para ver mais opções.',
    'Selecione seus serviços. Cada um terá seu próprio profissional e horário, inclusive em dias diferentes.',
    'Escolha seu profissional favorito ou veja os horários de toda a equipe.',
    'Escolha uma data e um horário disponível.',
    'Confira cada atendimento. Todos serão confirmados juntos, após uma nova validação dos horários.',
];
const ctas = [
    'Escolher unidade',
    'Escolher serviços',
    'Escolher barbeiro',
    'Ver datas e horários',
    'Revisar agendamento',
    'Confirmar agendamento',
];
const step = ref(0);
const name = ref('');
const email = ref('');
const phone = ref('');
const shopId = ref<number | null>(null);
const serviceIds = ref<number[]>([]);
const drafts = ref<BookingDraft[]>([]);
const activeServiceId = ref<number | null>(null);
const activeDraft = computed(() =>
    drafts.value.find((item) => item.service_id === activeServiceId.value),
);
const preference = computed({
    get: () => activeDraft.value?.preference ?? null,
    set: (value) => {
        if (activeDraft.value && activeDraft.value.preference !== value) {
            activeDraft.value.preference = value;
            activeDraft.value.time = '';
            activeDraft.value.barber_id = null;
        }
    },
});
const date = computed({
    get: () => activeDraft.value?.date ?? '',
    set: (value) => {
        if (activeDraft.value && activeDraft.value.date !== value) {
            activeDraft.value.date = value;
            activeDraft.value.time = '';
            activeDraft.value.barber_id = null;
        }
    },
});
const time = computed({
    get: () => activeDraft.value?.time ?? '',
    set: (value) => {
        if (activeDraft.value) {
            activeDraft.value.time = value;
        }
    },
});
const barberId = computed({
    get: () => activeDraft.value?.barber_id ?? null,
    set: (value) => {
        if (activeDraft.value) {
            activeDraft.value.barber_id = value;
        }
    },
});
const fetchedSlots = ref<Slot[]>([]);
const slots = computed(() =>
    fetchedSlots.value.filter(
        (slot) =>
            !hasOverlap(
                {
                    date: date.value,
                    time: slot.time,
                },
                activeServiceId.value,
            ),
    ),
);
const loading = ref(false);
const submitting = ref(false);
const error = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
const confirmedAppointments = ref<ConfirmedAppointment[]>([]);
const heading = ref<HTMLElement | null>(null);
const unitCarousel = ref<HTMLElement | null>(null);
const dateCarousel = ref<HTMLElement | null>(null);
let availabilityRequest: AbortController | null = null;

const shop = computed(() =>
    props.barbershops.find((item) => item.id === shopId.value),
);
const selectedServices = computed(
    () =>
        shop.value?.services.filter((item) =>
            serviceIds.value.includes(item.id),
        ) ?? [],
);
const activeService = computed(() =>
    selectedServices.value.find((item) => item.id === activeServiceId.value),
);
const allComplete = computed(
    () => drafts.value.length > 0 && drafts.value.every(isComplete),
);
const nextLabel = computed(() =>
    step.value === 4 &&
    drafts.value.some((item) => item !== activeDraft.value && !isComplete(item))
        ? 'Definir próximo serviço'
        : step.value === 4
          ? 'Revisar agendamentos'
          : ctas[step.value],
);
const totalDuration = computed(() =>
    selectedServices.value.reduce((total, item) => total + item.duration, 0),
);
const totalPrice = computed(() =>
    selectedServices.value.reduce(
        (total, item) => total + Number(item.price),
        0,
    ),
);
const eligibleBarbers = computed(
    () =>
        shop.value?.barbers.filter(
            (barber) =>
                activeServiceId.value !== null &&
                barber.service_ids.includes(activeServiceId.value),
        ) ?? [],
);
const preferenceLabel = computed(() =>
    preference.value === 'any'
        ? 'Horários de toda a equipe'
        : eligibleBarbers.value.find((item) => item.id === preference.value)
              ?.name,
);
const barber = computed(() =>
    eligibleBarbers.value.find((item) => item.id === barberId.value),
);
const slotBarbers = computed(() => {
    const selected = slots.value.find((slot) => slot.time === time.value);

    return eligibleBarbers.value.filter((item) =>
        selected?.barber_ids.includes(item.id),
    );
});
const dates = computed(() => {
    if (!shop.value) {
        return [];
    }

    const start = new Date(`${shop.value.today}T12:00:00Z`);

    return Array.from({ length: 46 }, (_, index) => {
        const day = new Date(start);
        day.setUTCDate(day.getUTCDate() + index);

        return {
            value: day.toISOString().slice(0, 10),
            weekday: new Intl.DateTimeFormat('pt-BR', {
                weekday: 'short',
                timeZone: 'UTC',
            }).format(day),
            label: new Intl.DateTimeFormat('pt-BR', {
                day: '2-digit',
                month: 'short',
                timeZone: 'UTC',
            }).format(day),
            today: index === 0,
        };
    });
});
const periods = computed(() => [
    { name: 'Manhã', slots: slots.value.filter((slot) => slot.time < '12:00') },
    {
        name: 'Tarde',
        slots: slots.value.filter(
            (slot) => slot.time >= '12:00' && slot.time < '18:00',
        ),
    },
    {
        name: 'Noite',
        slots: slots.value.filter((slot) => slot.time >= '18:00'),
    },
]);
function calendarEvent(appointment: ConfirmedAppointment) {
    const start = new Date(appointment.starts_at);
    const end = new Date(appointment.ends_at);
    const title = `${appointment.service.name} · ${shop.value?.name}`;
    const description = [
        `Agendamento #${appointment.id}`,
        `Serviço: ${appointment.service.name}`,
        `Duração: ${appointment.service.duration} min`,
        `Barbeiro: ${appointment.barber.name}`,
        `Unidade: ${shop.value?.name}`,
        ...(shop.value?.phones ?? []).map((contact) => `Contato: ${contact}`),
    ].join('\n');
    const details = {
        title,
        start,
        end,
        description,
        location: shop.value?.address ?? '',
    };
    const googleParams = new URLSearchParams({
        action: 'TEMPLATE',
        text: details.title,
        dates: `${calendarDateTime(start)}/${calendarDateTime(end)}`,
        details: details.description,
        location: details.location,
        ctz: appointment.timezone,
    });
    const outlookParams = new URLSearchParams({
        path: '/calendar/action/compose',
        rru: 'addevent',
        subject: details.title,
        startdt: start.toISOString(),
        enddt: end.toISOString(),
        body: details.description,
        location: details.location,
    });
    const ics = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Barbearia 85//Agendamento//PT-BR',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        `UID:appointment-${appointment.id}@barbearia85`,
        `DTSTAMP:${calendarDateTime(new Date())}`,
        `DTSTART:${calendarDateTime(start)}`,
        `DTEND:${calendarDateTime(end)}`,
        `SUMMARY:${escapeIcsText(details.title)}`,
        `DESCRIPTION:${escapeIcsText(details.description)}`,
        `LOCATION:${escapeIcsText(details.location)}`,
        'END:VEVENT',
        'END:VCALENDAR',
    ]
        .map(foldIcsLine)
        .join('\r\n');

    return {
        googleUrl: `https://calendar.google.com/calendar/render?${googleParams}`,
        outlookUrl: `https://outlook.live.com/calendar/0/deeplink/compose?${outlookParams}`,
        ics,
    };
}
const canContinue = computed(() => {
    if (loading.value || submitting.value) {
        return false;
    }

    return [
        Boolean(name.value.trim() && email.value.trim() && phone.value.trim()),
        Boolean(shop.value),
        serviceIds.value.length > 0,
        preference.value !== null,
        Boolean(
            time.value &&
            barber.value &&
            slots.value.some(
                (slot) =>
                    slot.time === time.value &&
                    barberId.value !== null &&
                    slot.barber_ids.includes(barberId.value),
            ),
        ),
        Boolean(shop.value && allComplete.value),
    ][step.value];
});
const money = (value: string) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value));
function calendarDateTime(value: Date) {
    return value
        .toISOString()
        .replace(/[-:]/g, '')
        .replace(/\.\d{3}/, '');
}
function escapeIcsText(value: string) {
    return value
        .replace(/\\/g, '\\\\')
        .replace(/\r\n|\r|\n/g, '\\n')
        .replace(/,/g, '\\,')
        .replace(/;/g, '\\;');
}
function foldIcsLine(line: string) {
    const folded: string[] = [];
    let current = '';
    let bytes = 0;
    let limit = 75;

    for (const character of line) {
        const characterBytes = new TextEncoder().encode(character).length;

        if (bytes + characterBytes > limit) {
            folded.push(current);
            current = character;
            bytes = characterBytes;
            limit = 74;
        } else {
            current += character;
            bytes += characterBytes;
        }
    }

    folded.push(current);

    return folded.join('\r\n ');
}
function saveAppleCalendar(appointment: ConfirmedAppointment) {
    const blob = new Blob([calendarEvent(appointment).ics], {
        type: 'text/calendar;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `agendamento-barbearia85-${appointment.id}.ics`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
const selectedClass = (selected: boolean) =>
    selected
        ? 'border-primary bg-primary/10 ring-1 ring-primary'
        : 'border-border hover:border-primary/60';

watch(shopId, () => {
    serviceIds.value = [];
    drafts.value = [];
    activeServiceId.value = null;
    fetchedSlots.value = [];
});
watch(
    () => serviceIds.value.join(','),
    () => {
        drafts.value = reconcileDrafts(serviceIds.value, drafts.value);

        if (!serviceIds.value.includes(activeServiceId.value ?? -1)) {
            activeServiceId.value = serviceIds.value[0] ?? null;
        }
    },
);
watch(step, () => heading.value?.focus(), { flush: 'post' });
watch([step, date, shopId, activeServiceId, preference], () => {
    if (step.value === 4 && date.value) {
        void loadAvailability();
    } else {
        availabilityRequest?.abort();
        fetchedSlots.value = [];
        loading.value = false;
    }
});
onBeforeUnmount(() => availabilityRequest?.abort());

async function loadAvailability() {
    availabilityRequest?.abort();
    const request = new AbortController();
    availabilityRequest = request;
    fetchedSlots.value = [];
    loading.value = true;
    error.value = '';
    fieldErrors.value = {};
    const query = new URLSearchParams({
        barbershop_id: String(shopId.value),
        service_id: String(activeServiceId.value),
        date: date.value,
    });
    const draft = activeDraft.value;
    const requestedDate = date.value;
    const requestedPreference = preference.value;

    if (typeof preference.value === 'number') {
        query.set('barber_id', String(preference.value));
    }

    try {
        const response = await fetch(`/agendar/disponibilidade?${query}`, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });

        if (request.signal.aborted) {
            return;
        }

        if (!response.ok) {
            error.value =
                response.status === 429
                    ? 'Muitas consultas. Aguarde um instante e tente novamente.'
                    : 'Não foi possível consultar os horários. Tente novamente.';

            if (response.status === 422) {
                const body: ApiError = await response.json();

                if (!request.signal.aborted) {
                    fieldErrors.value = body.errors ?? {};
                }
            }

            return;
        }

        const body: { slots: Slot[] } = await response.json();

        if (
            !request.signal.aborted &&
            draft === activeDraft.value &&
            requestedDate === date.value &&
            requestedPreference === preference.value
        ) {
            fetchedSlots.value = body.slots;

            if (
                draft?.time &&
                !slots.value.some(
                    (slot) =>
                        slot.time === draft.time &&
                        draft.barber_id !== null &&
                        slot.barber_ids.includes(draft.barber_id),
                )
            ) {
                draft.time = '';
                draft.barber_id = null;
            }
        }
    } catch (cause) {
        if (request.signal.aborted) {
            return;
        }

        if (!(cause instanceof Error)) {
            throw cause;
        }

        error.value =
            'Não foi possível consultar os horários. Verifique sua conexão e tente novamente.';
    } finally {
        if (availabilityRequest === request) {
            loading.value = false;
        }
    }
}

function toggleService(id: number) {
    serviceIds.value = serviceIds.value.includes(id)
        ? serviceIds.value.filter((selectedId) => selectedId !== id)
        : [...serviceIds.value, id];
}

function selectTime(slot: Slot) {
    time.value = slot.time;
    barberId.value =
        typeof preference.value === 'number' ? preference.value : null;
}

function hasOverlap(
    candidate: { date: string; time: string },
    serviceId: number | null,
): boolean {
    const duration =
        selectedServices.value.find((item) => item.id === serviceId)
            ?.duration ?? 0;

    return drafts.value.some(
        (other) =>
            other.service_id !== serviceId &&
            overlapsDraft(
                candidate,
                duration,
                other,
                selectedServices.value.find(
                    (item) => item.id === other.service_id,
                )?.duration ?? 0,
            ),
    );
}

function isComplete(item: BookingDraft): boolean {
    return Boolean(
        item.preference !== null &&
        item.date &&
        item.time &&
        item.barber_id &&
        shop.value?.barbers.some(
            (barber) =>
                barber.id === item.barber_id &&
                barber.service_ids.includes(item.service_id),
        ) &&
        !hasOverlap(item, item.service_id),
    );
}

function editItem(serviceId: number) {
    activeServiceId.value = serviceId;
    error.value = '';
    fieldErrors.value = {};
    step.value = 3;
}

function draftDetails(serviceId: number) {
    const draft = drafts.value.find((item) => item.service_id === serviceId);
    const service = selectedServices.value.find(
        (item) => item.id === serviceId,
    );
    const professional = shop.value?.barbers.find(
        (item) => item.id === draft?.barber_id,
    );

    return draft?.date && draft.time && service
        ? `${draft.date.split('-').reverse().join('/')} · ${draft.time}–${endTime(draft.time, service.duration)} · ${professional?.name ?? 'Escolha o barbeiro'}`
        : 'Defina o profissional, a data e o horário';
}

function confirmedDate(appointment: ConfirmedAppointment) {
    const formatter = new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'full',
        timeStyle: 'short',
        timeZone: appointment.timezone,
    });
    const end = new Intl.DateTimeFormat('pt-BR', {
        timeStyle: 'short',
        timeZone: appointment.timezone,
    }).format(new Date(appointment.ends_at));

    return `${formatter.format(new Date(appointment.starts_at))} até ${end}`;
}

async function next() {
    if (!canContinue.value) {
        return;
    }

    error.value = '';
    fieldErrors.value = {};

    if (step.value === 4) {
        const remaining = drafts.value.find((item) => !isComplete(item));

        if (remaining) {
            editItem(remaining.service_id);
        } else {
            step.value = 5;
        }

        return;
    }

    if (step.value < 5) {
        step.value++;

        return;
    }

    submitting.value = true;

    try {
        const response = await fetch('/agendar', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
            body: JSON.stringify({
                name: name.value.trim(),
                email: email.value.trim(),
                phone: phone.value.trim(),
                barbershop_id: shopId.value,
                items: drafts.value.map(
                    ({ service_id, barber_id, date, time }) => ({
                        service_id,
                        barber_id,
                        date,
                        time,
                    }),
                ),
            }),
        });

        if (!response.ok) {
            if (response.status === 422) {
                const body: ApiError = await response.json();
                fieldErrors.value = body.errors ?? {};
                error.value =
                    'Nenhuma reserva foi confirmada. Revise os dados e os serviços indicados abaixo.';

                for (const field of Object.keys(fieldErrors.value)) {
                    const match = /^items\.(\d+)\./.exec(field);
                    const draft = match ? drafts.value[Number(match[1])] : null;

                    if (draft) {
                        draft.time = '';
                        draft.barber_id = null;
                    }
                }
            } else {
                error.value =
                    response.status === 419
                        ? 'Sua sessão expirou. Recarregue a página antes de confirmar.'
                        : response.status === 429
                          ? 'Muitas tentativas. Aguarde um minuto antes de confirmar novamente.'
                          : 'Não foi possível confirmar. Verifique com a unidade se a reserva foi registrada antes de tentar novamente.';
            }

            return;
        }

        const body: { appointments: ConfirmedAppointment[] } =
            await response.json();
        confirmedAppointments.value = body.appointments;
    } catch (cause) {
        if (!(cause instanceof Error)) {
            throw cause;
        }

        error.value =
            'A conexão foi interrompida. Verifique com a unidade se a reserva foi registrada antes de tentar novamente.';
    } finally {
        submitting.value = false;
    }
}

function back() {
    error.value = '';
    fieldErrors.value = {};
    step.value--;
}
</script>

<template>
    <Head title="Agendar Horário" />
    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex max-w-6xl items-center justify-between px-5 py-6"
        >
            <Link href="/" class="flex items-center gap-3 font-semibold">
                <img
                    src="/img/Barbearia85.png"
                    alt="Barbearia 85"
                    class="size-12 rounded-full"
                />
                <span>Barbearia 85</span>
            </Link>
            <Link
                href="/"
                class="text-sm text-muted-foreground hover:text-primary"
                >Voltar ao início</Link
            >
        </header>
        <main class="mx-auto max-w-6xl px-5 pb-12" aria-label="Agendar Horário">
            <template v-if="confirmedAppointments.length">
                <section
                    class="mx-auto max-w-2xl rounded-2xl border border-primary bg-primary/5 p-6 sm:p-10"
                    role="status"
                >
                    <p class="mb-3 font-semibold text-primary">
                        {{ confirmedAppointments.length }} atendimento(s)
                        confirmado(s)
                    </p>
                    <h1 class="text-3xl font-bold">
                        Seu próximo cuidado já tem hora marcada!
                    </h1>
                    <p class="mt-4 text-muted-foreground">
                        {{ name }}, esperamos você em {{ shop?.name }}.
                    </p>
                    <p class="mt-2">{{ shop?.address }}</p>
                    <p
                        v-for="contact in shop?.phones"
                        :key="contact"
                        class="mt-1 text-sm"
                    >
                        Contato da unidade: {{ contact }}
                    </p>
                    <p class="mt-5 text-sm text-muted-foreground">
                        Guarde os detalhes da sua reserva. Em caso de
                        imprevisto, entre em contato com a unidade.
                    </p>
                    <div
                        v-for="appointment in confirmedAppointments"
                        :key="appointment.id"
                        class="mt-6 space-y-3 rounded-xl border p-4"
                    >
                        <h2 class="font-semibold">
                            {{ appointment.service.name }} · #{{
                                appointment.id
                            }}
                        </h2>
                        <p>{{ confirmedDate(appointment) }}</p>
                        <p>
                            {{ appointment.barber.name }} ·
                            {{ appointment.service.duration }} min ·
                            {{ money(appointment.service.price) }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ appointment.timezone }}
                        </p>
                        <h3 class="font-semibold">
                            Adicionar este atendimento ao calendário
                        </h3>
                        <div class="flex flex-wrap gap-3">
                            <Button as-child variant="outline">
                                <a
                                    :href="calendarEvent(appointment).googleUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Salvar no Google Calendar
                                </a>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="saveAppleCalendar(appointment)"
                            >
                                Salvar no Apple Calendar
                            </Button>
                            <Button as-child variant="outline">
                                <a
                                    :href="
                                        calendarEvent(appointment).outlookUrl
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Salvar no Microsoft Outlook
                                </a>
                            </Button>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            O Apple Calendar será salvo como um arquivo .ics
                            para importar no seu dispositivo.
                        </p>
                    </div>
                    <Button as-child class="mt-6">
                        <Link href="/">Voltar ao início</Link>
                    </Button>
                </section>
            </template>
            <template v-else>
                <nav
                    aria-label="Etapas do agendamento"
                    class="mb-8 overflow-x-auto"
                >
                    <ol
                        class="flex min-w-max gap-3 py-2 sm:w-full sm:justify-between"
                    >
                        <li
                            v-for="(label, index) in labels"
                            :key="label"
                            class="flex items-center gap-2 text-sm"
                            :aria-current="index === step ? 'step' : undefined"
                        >
                            <span
                                class="flex size-8 items-center justify-center rounded-full border font-semibold"
                                :class="
                                    index <= step
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'text-muted-foreground'
                                "
                                >{{ index < step ? '✓' : index + 1 }}</span
                            >
                            <span
                                :class="
                                    index === step
                                        ? 'font-semibold'
                                        : 'text-muted-foreground'
                                "
                                >{{ label }}</span
                            >
                        </li>
                    </ol>
                </nav>
                <div class="mb-8">
                    <p class="mb-2 text-sm font-semibold text-primary">
                        Passo {{ step + 1 }} de 6
                    </p>
                    <h1
                        ref="heading"
                        tabindex="-1"
                        class="text-3xl font-bold tracking-tight outline-none sm:text-4xl"
                    >
                        {{ titles[step] }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-muted-foreground">
                        {{ copies[step] }}
                    </p>
                </div>
                <form @submit.prevent="next">
                    <section
                        v-if="step >= 3"
                        aria-label="Seus atendimentos"
                        class="mb-6 space-y-3"
                    >
                        <h2 class="font-semibold">
                            Organize cada serviço separadamente
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Os horários podem ser em dias diferentes, mas não
                            podem se sobrepor. Nenhum horário está reservado até
                            a confirmação final.
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                v-for="item in selectedServices"
                                :key="item.id"
                                type="button"
                                class="rounded-xl border p-4 text-left"
                                :class="
                                    selectedClass(
                                        activeServiceId === item.id && step < 5,
                                    )
                                "
                                :disabled="submitting"
                                @click="editItem(item.id)"
                            >
                                <span class="block font-semibold"
                                    >{{ item.name }} · {{ item.duration }} min ·
                                    {{ money(item.price) }}</span
                                >
                                <span class="mt-1 block text-sm">{{
                                    draftDetails(item.id)
                                }}</span>
                                <span class="mt-2 block text-sm text-primary">{{
                                    drafts.find(
                                        (draft) =>
                                            draft.service_id === item.id &&
                                            isComplete(draft),
                                    )
                                        ? 'Definido · Editar'
                                        : 'A definir'
                                }}</span>
                                <span
                                    v-for="(messages, field) in fieldErrors"
                                    :key="field"
                                >
                                    <span
                                        v-if="
                                            String(field).startsWith(
                                                `items.${drafts.findIndex((draft) => draft.service_id === item.id)}.`,
                                            )
                                        "
                                        class="mt-1 block text-sm text-destructive"
                                        >{{ messages.join(' ') }}</span
                                    >
                                </span>
                            </button>
                        </div>
                        <p v-if="step < 5" class="font-semibold text-primary">
                            Definindo: {{ activeService?.name }}
                        </p>
                    </section>
                    <div
                        v-if="step === 0"
                        class="grid max-w-xl gap-5 rounded-2xl border p-6"
                    >
                        <div class="grid gap-2">
                            <Label for="booking-name">Seu nome</Label>
                            <Input
                                id="booking-name"
                                v-model="name"
                                autocomplete="name"
                                placeholder="Como podemos chamar você?"
                                required
                                maxlength="255"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="booking-email">E-mail</Label>
                            <Input
                                id="booking-email"
                                v-model="email"
                                type="email"
                                autocomplete="email"
                                placeholder="voce@exemplo.com"
                                required
                                maxlength="255"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="booking-phone">Telefone</Label>
                            <PhoneInput
                                id="booking-phone"
                                v-model="phone"
                                required
                            />
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Seus dados serão usados para identificar a reserva e
                            permitir o contato sobre seu atendimento.
                        </p>
                    </div>
                    <section v-if="step === 1" aria-label="Unidades">
                        <div class="mb-3 flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                aria-label="Unidades anteriores"
                                @click="
                                    unitCarousel?.scrollBy({
                                        left: -360,
                                        behavior: 'smooth',
                                    })
                                "
                                >←</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                aria-label="Próximas unidades"
                                @click="
                                    unitCarousel?.scrollBy({
                                        left: 360,
                                        behavior: 'smooth',
                                    })
                                "
                                >→</Button
                            >
                        </div>
                        <div
                            ref="unitCarousel"
                            class="flex snap-x snap-mandatory gap-5 overflow-x-auto p-1 pb-5"
                        >
                            <article
                                v-for="unit in barbershops"
                                :key="unit.id"
                                class="flex w-[min(85vw,350px)] shrink-0 snap-start flex-col gap-4 rounded-2xl border p-4 transition-colors"
                                :class="selectedClass(shopId === unit.id)"
                            >
                                <BookingGallery
                                    :photos="unit.photos"
                                    :name="unit.name"
                                />
                                <h2 class="text-xl font-semibold">
                                    {{ unit.name }}
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    {{ unit.description }}
                                </p>
                                <p class="text-sm">{{ unit.address }}</p>
                                <a
                                    v-for="contact in unit.phones"
                                    :key="contact"
                                    :href="`tel:${contact.replace(/[^\d+]/g, '')}`"
                                    class="text-sm underline underline-offset-4"
                                    >{{ contact }}</a
                                >
                                <Button
                                    type="button"
                                    class="mt-auto"
                                    :variant="
                                        shopId === unit.id
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :aria-pressed="shopId === unit.id"
                                    @click="shopId = unit.id"
                                    >{{
                                        shopId === unit.id
                                            ? 'Unidade selecionada'
                                            : 'Escolher esta unidade'
                                    }}</Button
                                >
                            </article>
                        </div>
                        <p
                            v-if="!barbershops.length"
                            class="rounded-xl border p-6 text-muted-foreground"
                        >
                            Nenhuma unidade está disponível para agendamento no
                            momento. Volte em breve.
                        </p>
                    </section>
                    <section
                        v-if="step === 2"
                        aria-label="Serviços disponíveis"
                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <button
                            v-for="item in shop?.services"
                            :key="item.id"
                            type="button"
                            class="flex flex-col gap-3 rounded-2xl border p-6 text-left transition-colors"
                            :class="selectedClass(serviceIds.includes(item.id))"
                            :aria-pressed="serviceIds.includes(item.id)"
                            @click="toggleService(item.id)"
                        >
                            <span class="text-xl font-semibold">{{
                                item.name
                            }}</span>
                            <span class="text-sm text-muted-foreground">{{
                                item.description
                            }}</span>
                            <span
                                class="mt-auto flex w-full items-center justify-between gap-3 pt-4"
                                ><span class="text-sm"
                                    >{{ item.duration }} min</span
                                ><span class="font-semibold text-primary">{{
                                    money(item.price)
                                }}</span></span
                            >
                        </button>
                        <p
                            v-if="!shop?.services.length"
                            class="rounded-xl border p-6 text-muted-foreground sm:col-span-2"
                        >
                            Esta unidade ainda não possui serviços disponíveis.
                            Volte e escolha outra unidade.
                        </p>
                    </section>
                    <section
                        v-if="step === 3"
                        aria-label="Preferência de barbeiro"
                    >
                        <p
                            v-if="!eligibleBarbers.length"
                            role="status"
                            class="mb-4 rounded-xl border p-4 text-muted-foreground"
                        >
                            Não há profissionais habilitados para este serviço
                            na unidade. Volte à seleção de serviços ou escolha
                            outra unidade.
                        </p>
                        <button
                            type="button"
                            class="mb-6 w-full rounded-2xl border p-6 text-left transition-colors"
                            :class="selectedClass(preference === 'any')"
                            :aria-pressed="preference === 'any'"
                            @click="preference = 'any'"
                        >
                            <span class="block text-lg font-semibold"
                                >Sem preferência de barbeiro</span
                            >
                            <span
                                class="mt-2 block text-sm text-muted-foreground"
                                >Veja os horários de toda a equipe. Depois de
                                escolher o horário, selecione um dos
                                profissionais disponíveis.</span
                            >
                        </button>
                        <h2 class="mb-4 font-semibold">
                            Ou escolha seu barbeiro de preferência
                        </h2>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <button
                                v-for="item in eligibleBarbers"
                                :key="item.id"
                                type="button"
                                class="flex items-center gap-4 rounded-2xl border p-5 text-left transition-colors"
                                :class="selectedClass(preference === item.id)"
                                :aria-pressed="preference === item.id"
                                @click="preference = item.id"
                            >
                                <img
                                    v-if="item.avatar"
                                    :src="item.avatar"
                                    :alt="item.name"
                                    class="size-16 rounded-full object-cover"
                                />
                                <span
                                    v-else
                                    class="flex size-16 shrink-0 items-center justify-center rounded-full bg-muted text-xl font-semibold"
                                    aria-hidden="true"
                                    >{{ item.name.slice(0, 1) }}</span
                                >
                                <span
                                    ><span class="block font-semibold">{{
                                        item.name
                                    }}</span
                                    ><span class="text-sm text-muted-foreground"
                                        >Atende o serviço escolhido</span
                                    ></span
                                >
                            </button>
                        </div>
                    </section>
                    <section v-if="step === 4" aria-label="Data e horário">
                        <div
                            class="mb-3 flex items-center justify-between gap-3"
                        >
                            <p class="text-sm text-muted-foreground">
                                {{ preferenceLabel }} · {{ shop?.timezone }}
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    aria-label="Datas anteriores"
                                    @click="
                                        dateCarousel?.scrollBy({
                                            left: -440,
                                            behavior: 'smooth',
                                        })
                                    "
                                    >←</Button
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    aria-label="Próximas datas"
                                    @click="
                                        dateCarousel?.scrollBy({
                                            left: 440,
                                            behavior: 'smooth',
                                        })
                                    "
                                    >→</Button
                                >
                            </div>
                        </div>
                        <div
                            ref="dateCarousel"
                            class="flex snap-x snap-mandatory gap-3 overflow-x-auto p-1 pb-5"
                        >
                            <button
                                v-for="day in dates"
                                :key="day.value"
                                type="button"
                                class="flex w-28 shrink-0 snap-start flex-col items-center gap-2 rounded-xl border p-4 transition-colors"
                                :class="selectedClass(date === day.value)"
                                :aria-pressed="date === day.value"
                                @click="date = day.value"
                            >
                                <span class="text-xs text-muted-foreground">{{
                                    day.today ? 'Hoje' : day.weekday
                                }}</span>
                                <span class="font-semibold">{{
                                    day.label
                                }}</span>
                            </button>
                        </div>
                        <div
                            aria-live="polite"
                            :aria-busy="loading"
                            class="mt-4"
                        >
                            <p
                                v-if="
                                    date &&
                                    !loading &&
                                    fetchedSlots.length !== slots.length
                                "
                                class="mb-4 text-sm text-muted-foreground"
                            >
                                Horários que coincidem com os outros serviços
                                escolhidos foram ocultados. Edite os outros
                                atendimentos se precisar liberar essas opções.
                            </p>
                            <p v-if="!date" class="text-muted-foreground">
                                Selecione uma data para consultar os horários.
                            </p>
                            <p
                                v-else-if="loading"
                                class="text-muted-foreground"
                            >
                                Buscando horários disponíveis…
                            </p>
                            <template v-else-if="!error">
                                <p
                                    v-if="!slots.length"
                                    class="rounded-xl border p-6 text-muted-foreground"
                                >
                                    Não há horários disponíveis nesta data.
                                    Experimente outro dia{{
                                        preference !== 'any'
                                            ? ' ou volte para escolher outro barbeiro'
                                            : ''
                                    }}.
                                </p>
                                <div
                                    v-for="period in periods"
                                    :key="period.name"
                                    class="mb-6"
                                >
                                    <template v-if="period.slots.length">
                                        <h2 class="mb-3 font-semibold">
                                            {{ period.name }}
                                        </h2>
                                        <div class="flex flex-wrap gap-3">
                                            <button
                                                v-for="slot in period.slots"
                                                :key="slot.time"
                                                type="button"
                                                class="rounded-full border px-5 py-2.5 font-medium transition-colors"
                                                :class="
                                                    selectedClass(
                                                        time === slot.time,
                                                    )
                                                "
                                                :aria-pressed="
                                                    time === slot.time
                                                "
                                                @click="selectTime(slot)"
                                            >
                                                {{ slot.time }}
                                            </button>
                                        </div>
                                    </template>
                                </div>
                                <div
                                    v-if="time && preference === 'any'"
                                    class="mt-6 rounded-2xl border p-5"
                                >
                                    <h2 class="font-semibold">
                                        Quem vai cuidar de você às {{ time }}?
                                    </h2>
                                    <p
                                        class="mt-2 text-sm text-muted-foreground"
                                    >
                                        Escolha um dos barbeiros disponíveis
                                        para este horário.
                                    </p>
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <button
                                            v-for="item in slotBarbers"
                                            :key="item.id"
                                            type="button"
                                            class="flex items-center gap-3 rounded-xl border px-4 py-3"
                                            :class="
                                                selectedClass(
                                                    barberId === item.id,
                                                )
                                            "
                                            :aria-pressed="barberId === item.id"
                                            @click="barberId = item.id"
                                        >
                                            <img
                                                v-if="item.avatar"
                                                :src="item.avatar"
                                                :alt="item.name"
                                                class="size-10 rounded-full object-cover"
                                            />
                                            {{ item.name }}
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <Button
                            v-if="error && date"
                            type="button"
                            variant="outline"
                            class="mt-4"
                            :disabled="loading"
                            @click="loadAvailability"
                            >Consultar horários novamente</Button
                        >
                    </section>
                    <section
                        v-if="step === 5"
                        aria-label="Resumo do agendamento"
                        class="grid gap-5 md:grid-cols-2"
                    >
                        <div class="rounded-2xl border p-6">
                            <h2 class="text-lg font-semibold">
                                Sua experiência
                            </h2>
                            <dl class="mt-5 grid gap-5">
                                <div>
                                    <dt class="text-sm text-muted-foreground">
                                        Unidade
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ shop?.name }}
                                    </dd>
                                    <dd class="mt-1 text-sm">
                                        {{ shop?.description }}
                                    </dd>
                                    <dd class="mt-1 text-sm">
                                        {{ shop?.address }}
                                    </dd>
                                    <dd
                                        v-for="contact in shop?.phones"
                                        :key="contact"
                                        class="mt-1 text-sm"
                                    >
                                        {{ contact }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-muted-foreground">
                                        Serviços
                                    </dt>
                                    <dd
                                        v-for="item in selectedServices"
                                        :key="item.id"
                                        class="mt-3 rounded-xl border p-4"
                                    >
                                        <p class="font-semibold">
                                            {{ item.name }} ·
                                            {{ item.duration }} min ·
                                            {{ money(item.price) }}
                                        </p>
                                        <p class="mt-2 text-sm">
                                            {{ draftDetails(item.id) }}
                                        </p>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{ shop?.timezone }}
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            class="mt-3"
                                            :disabled="submitting"
                                            @click="editItem(item.id)"
                                            >Editar atendimento</Button
                                        >
                                    </dd>
                                    <dd
                                        class="mt-2 border-t pt-2 font-semibold"
                                    >
                                        Total de atendimento:
                                        {{ totalDuration }} min ·
                                        {{ money(String(totalPrice)) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div class="rounded-2xl border p-6">
                            <h2 class="text-lg font-semibold">Seus dados</h2>
                            <dl class="mt-5 grid gap-4">
                                <div>
                                    <dt class="text-sm text-muted-foreground">
                                        Nome
                                    </dt>
                                    <dd class="mt-1 break-words">{{ name }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-muted-foreground">
                                        E-mail
                                    </dt>
                                    <dd class="mt-1 break-all">{{ email }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-muted-foreground">
                                        Telefone
                                    </dt>
                                    <dd class="mt-1">{{ phone }}</dd>
                                </div>
                            </dl>
                            <Button
                                type="button"
                                variant="outline"
                                class="mt-6"
                                :disabled="submitting"
                                @click="step = 0"
                                >Editar meus dados</Button
                            >
                            <p class="mt-6 text-sm text-muted-foreground">
                                Ao confirmar, todos os atendimentos serão
                                registrados juntos. Se algum horário não estiver
                                disponível, nenhum será reservado. Para
                                alterações ou cancelamento, entre em contato com
                                a unidade.
                            </p>
                        </div>
                    </section>
                    <div
                        v-if="error || Object.keys(fieldErrors).length"
                        role="alert"
                        class="mt-6 rounded-xl border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                    >
                        <p>{{ error }}</p>
                        <p
                            v-for="(messages, field) in fieldErrors"
                            :key="field"
                            class="mt-2"
                        >
                            {{ messages.join(' ') }}
                        </p>
                    </div>
                    <footer
                        class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t pt-6"
                    >
                        <Button
                            v-if="step > 0"
                            type="button"
                            variant="outline"
                            :disabled="submitting"
                            @click="back"
                            >← Voltar</Button
                        >
                        <p v-else class="text-sm text-muted-foreground">
                            Seu estilo começa aqui.
                        </p>
                        <Button
                            type="submit"
                            :disabled="!canContinue"
                            class="ml-auto"
                            >{{ submitting ? 'Confirmando…' : nextLabel }}
                            <span v-if="!submitting" aria-hidden="true"
                                >→</span
                            ></Button
                        >
                    </footer>
                </form>
            </template>
        </main>
        <PublicFooter />
    </div>
</template>

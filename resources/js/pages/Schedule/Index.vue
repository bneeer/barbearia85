<script setup lang="ts">
import type { CalendarOptions, EventApi, EventInput } from '@fullcalendar/core';
import ptBrLocale from '@fullcalendar/core/locales/pt-br';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import luxonPlugin from '@fullcalendar/luxon3';
import timeGridPlugin from '@fullcalendar/timegrid';
import FullCalendar from '@fullcalendar/vue3';
import { Head } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    LockKeyhole,
    Plus,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PhoneInput from '@/components/PhoneInput.vue';
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
import { DISPLAY_TIMEZONE } from '@/lib/dateTime';

type Service = { id: number; name: string; duration: number; price: string };
type Product = {
    id: number;
    name: string;
    brand: string;
    price: string;
    stock_quantity: number;
};
type Checkout = {
    services: {
        id: number;
        name: string;
        duration: number;
        price_cents: number;
    }[];
    products: {
        id: number;
        name: string;
        brand: string;
        quantity: number;
        price_cents: number;
    }[];
    services_total_cents: number;
    products_total_cents: number;
    discount_cents: number;
    total_cents: number;
    payment_method: string;
    notes: string | null;
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
    address: string | null;
    timezone: string;
    is_open: boolean;
    today: string;
    photo: string | null;
    barbers: Barber[];
    services: Service[];
    products: Product[];
};
type Details = {
    kind: 'appointment' | 'block';
    recordId: number;
    barberId: number;
    barberName: string;
    client?: { name: string; email: string; phone: string };
    services?: { id: number; name: string; duration: number; price: string }[];
    status?: string;
    notes?: string;
    reason?: string;
    version?: string;
    checkout?: Checkout | null;
    canCheckout?: boolean;
    noShowEligibleAt?: string | null;
    checkoutUnavailableReason?: string | null;
};
type Selection = { details: Details; start: string; end: string };
type CalendarSelection = {
    start: string;
    end: string;
    startsAt: number;
    allDay: boolean;
    durationMinutes: number | null;
};
type ApiError = { message?: string; errors?: Record<string, string[]> };

const props = defineProps<{
    barbershops: Shop[];
    csrfToken: string;
    canManageAppointments: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Agendamentos', href: '/schedule' }] },
});

const calendar = ref<InstanceType<typeof FullCalendar> | null>(null);
const carousel = ref<HTMLElement | null>(null);
const shopId = ref<number | null>(null);
const barberId = ref('');
const shop = computed(() =>
    props.barbershops.find((unit) => unit.id === shopId.value),
);
const latestDate = computed(() => {
    if (!shop.value) {
        return '';
    }

    const date = new Date(`${shop.value.today}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 45);

    return date.toISOString().slice(0, 10);
});
const createBarber = computed(() =>
    shop.value?.barbers.find(
        (barber) => barber.id === Number(createBarberId.value),
    ),
);
const creationServices = computed(
    () =>
        shop.value?.services.filter((service) =>
            createBarber.value?.service_ids.includes(service.id),
        ) ?? [],
);
const selectedCreationServices = computed(() =>
    creationServices.value.filter((service) =>
        createServiceIds.value.includes(service.id),
    ),
);
const createDuration = computed(() =>
    selectedCreationServices.value.reduce(
        (total, service) => total + service.duration,
        0,
    ),
);
const createPrice = computed(() =>
    selectedCreationServices.value.reduce(
        (total, service) => total + Number(service.price),
        0,
    ),
);
const creationSlots = computed(() =>
    availableSlots.value.filter((slot) => {
        const hour = Number(slot.time.slice(0, 2));
        const inPeriod =
            createPeriod.value === 'morning'
                ? hour < 12
                : createPeriod.value === 'afternoon'
                  ? hour >= 12 && hour < 18
                  : hour >= 18;

        return (
            inPeriod && slot.barber_ids.includes(Number(createBarberId.value))
        );
    }),
);
const selectedRangeTooShort = computed(() => {
    return Boolean(
        selectedRangeMinutes.value !== null &&
        createServiceIds.value.length > 0 &&
        selectedRangeMinutes.value < createDuration.value,
    );
});
const loading = ref(false);
const busy = ref(false);
const loadError = ref('');
const formError = ref('');
const selected = ref<Selection | null>(null);
const rangeChoiceOpen = ref(false);
const calendarSelection = ref<CalendarSelection | null>(null);
const detailsOpen = ref(false);
const checkoutOpen = ref(false);
const checkoutProducts = ref<{ id: number; quantity: number }[]>([]);
const productToAdd = ref('');
const discount = ref<string | number>('');
const paymentMethod = ref('');
const checkoutNotes = ref('');
const checkoutError = ref('');
const createAppointmentOpen = ref(false);
const blockOpen = ref(false);
const confirmOpen = ref(false);
const confirmAction = ref<'cancel' | 'delete' | 'no-show'>('cancel');
const now = useNow({ interval: 1000 });
const canMarkNoShow = computed(() => {
    const details = selected.value?.details;

    return details?.status === 'scheduled' && details.noShowEligibleAt
        ? now.value.getTime() >= Date.parse(details.noShowEligibleAt)
        : false;
});
const start = ref('');
const end = ref('');
const reason = ref('');
const fullDay = ref(false);
const blockDate = ref('');
const blockBarbers = ref<number[]>([]);
const editingBlock = ref<number | null>(null);
const clientName = ref('');
const clientEmail = ref('');
const clientPhone = ref('');
const clientNotes = ref('');
const createDate = ref('');
const selectedRangeMinutes = ref<number | null>(null);
const preferredCreateTime = ref('');
const createPeriod = ref<'morning' | 'afternoon' | 'evening'>('morning');
const createBarberId = ref('');
const createServiceIds = ref<number[]>([]);
const createTime = ref('');
const availableSlots = ref<{ time: string; barber_ids: number[] }[]>([]);
const slotsLoading = ref(false);
const slotsError = ref('');
const createError = ref('');
const creatingAppointment = ref(false);
let slotsRequest: AbortController | null = null;
const selectClass =
    'h-10 rounded-md border border-input bg-background px-3 text-sm';
const statusLabels: Record<string, string> = {
    scheduled: 'Agendado',
    completed: 'Concluído',
    no_show: 'Não compareceu',
};
const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const paymentLabels: Record<string, string> = {
    credit_card: 'Cartão de Crédito',
    debit_card: 'Cartão de Débito',
    pix: 'PIX',
    cash: 'Dinheiro',
};
const serviceTotalCents = computed(() =>
    (selected.value?.details.services ?? []).reduce(
        (sum, service) => sum + Math.round(Number(service.price) * 100),
        0,
    ),
);
const checkoutLines = computed(() =>
    checkoutProducts.value.map((item) => ({
        ...item,
        product: shop.value?.products.find((product) => product.id === item.id),
    })),
);
const productTotalCents = computed(() =>
    checkoutLines.value.reduce(
        (sum, item) =>
            sum +
            Math.round(Number(item.product?.price ?? 0) * 100) * item.quantity,
        0,
    ),
);
const discountCents = computed(() =>
    discount.value === ''
        ? 0
        : Math.round(Number(String(discount.value).replace(',', '.')) * 100),
);
const subtotalCents = computed(
    () => serviceTotalCents.value + productTotalCents.value,
);
const validDiscount = computed(
    () =>
        (discount.value === '' ||
            /^\d+([.,]\d{1,2})?$/.test(String(discount.value))) &&
        Number.isSafeInteger(discountCents.value) &&
        discountCents.value >= 0 &&
        discountCents.value <= subtotalCents.value,
);
function localTime(iso: string) {
    if (!shop.value) {
        return '';
    }

    const parts = new Intl.DateTimeFormat('sv-SE', {
        timeZone: DISPLAY_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((entry) => entry.type === type)?.value;

    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}
function eventSelection(event: EventApi): Selection {
    return {
        details: event.extendedProps as Details,
        start: localTime(event.start!.toISOString()),
        end: localTime(event.end!.toISOString()),
    };
}
async function request<T>(
    url: string,
    method = 'GET',
    data?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': props.csrfToken,
        },
        ...(data ? { body: JSON.stringify(data) } : {}),
        ...(signal ? { signal } : {}),
    });

    if (!response.ok) {
        const body: ApiError = await response.json();

        throw new Error(
            Object.values(body.errors ?? {})
                .flat()
                .join(' ') ||
                (response.status === 419
                    ? 'Sua sessão expirou. Recarregue a página.'
                    : response.status === 409
                      ? 'O agendamento foi alterado. Atualize o calendário antes de tentar novamente.'
                      : body.message ||
                        'Não foi possível concluir a operação.'),
        );
    }

    return response.json() as Promise<T>;
}
function message(error: unknown) {
    return error instanceof Error
        ? error.message
        : 'Não foi possível concluir a operação.';
}
function selectShop(unit: Shop) {
    if (busy.value || unit.id === shopId.value) {
        return;
    }

    shopId.value = unit.id;
    barberId.value = '';
    loadError.value = '';
    selected.value = null;
}
function refresh() {
    calendar.value?.getApi().refetchEvents();
}
function isPastSelection(selection: CalendarSelection | null) {
    if (!selection) {
        return false;
    }

    return selection.allDay
        ? selection.start.slice(0, 10) <
              localTime(new Date().toISOString()).slice(0, 10)
        : selection.startsAt <= Date.now();
}
function openCreateAppointment(selection: CalendarSelection | null = null) {
    if (!shop.value || !shop.value.barbers.length || busy.value) {
        return;
    }

    if (isPastSelection(selection)) {
        toast.error('Escolha uma data e um horário futuros para agendar.');

        return;
    }

    const currentDate = calendar.value?.getApi().getDate();
    const calendarDay = currentDate
        ? localTime(currentDate.toISOString()).slice(0, 10)
        : shop.value.today;
    const day = selection
        ? selection.start.slice(0, 10)
        : calendarDay < shop.value.today
          ? shop.value.today
          : calendarDay;
    clientName.value = '';
    clientEmail.value = '';
    clientPhone.value = '';
    clientNotes.value = '';
    createDate.value = day;
    selectedRangeMinutes.value = selection?.durationMinutes ?? null;
    preferredCreateTime.value =
        selection && !selection.allDay ? selection.start.slice(11, 16) : '';
    createPeriod.value = !preferredCreateTime.value
        ? 'morning'
        : Number(preferredCreateTime.value.slice(0, 2)) < 12
          ? 'morning'
          : Number(preferredCreateTime.value.slice(0, 2)) < 18
            ? 'afternoon'
            : 'evening';
    createBarberId.value = barberId.value || String(shop.value.barbers[0].id);
    createServiceIds.value = [];
    createTime.value = preferredCreateTime.value;
    createError.value = '';
    slotsError.value = '';
    availableSlots.value = [];
    createAppointmentOpen.value = true;
}
watch(
    [
        createAppointmentOpen,
        shopId,
        createDate,
        createBarberId,
        () => createServiceIds.value.join(','),
    ],
    async ([isOpen, unitId, date, selectedBarberId, serviceIds]) => {
        slotsRequest?.abort();
        availableSlots.value = [];
        createTime.value = preferredCreateTime.value;
        slotsError.value = '';

        if (!isOpen || !unitId || !date || !selectedBarberId || !serviceIds) {
            slotsLoading.value = false;

            return;
        }

        const controller = new AbortController();
        slotsRequest = controller;
        slotsLoading.value = true;
        const params = new URLSearchParams({
            barbershop_id: String(unitId),
            barber_id: selectedBarberId,
            date,
        });
        serviceIds
            .split(',')
            .forEach((id, index) => params.set(`service_ids[${index}]`, id));

        try {
            const result = await request<{
                slots: { time: string; barber_ids: number[] }[];
            }>(
                `/agendar/disponibilidade?${params}`,
                'GET',
                undefined,
                controller.signal,
            );

            if (!controller.signal.aborted) {
                availableSlots.value = result.slots;
                createTime.value = creationSlots.value.some(
                    (slot) => slot.time === preferredCreateTime.value,
                )
                    ? preferredCreateTime.value
                    : '';
            }
        } catch (error) {
            if (!controller.signal.aborted) {
                slotsError.value = message(error);
            }
        } finally {
            if (slotsRequest === controller) {
                slotsLoading.value = false;
                slotsRequest = null;
            }
        }
    },
);
async function chooseCalendarAction(action: 'appointment' | 'block') {
    const selection = calendarSelection.value;

    if (!selection || busy.value) {
        return;
    }

    if (isPastSelection(selection)) {
        toast.error('Escolha um período futuro para agendar ou bloquear.');

        return;
    }

    rangeChoiceOpen.value = false;
    await nextTick();

    if (action === 'appointment') {
        openCreateAppointment(selection);
    } else {
        showBlock(selection.start, selection.end, selection.allDay);
    }
}
watch(createBarberId, () => {
    createServiceIds.value = createServiceIds.value.filter((id) =>
        creationServices.value.some((service) => service.id === id),
    );
});
async function submitCreateAppointment() {
    if (!shop.value || !createBarber.value || creatingAppointment.value) {
        return;
    }

    if (selectedRangeTooShort.value) {
        createError.value =
            'O intervalo selecionado é menor que a duração do serviço. Selecione uma faixa maior ou escolha outro serviço.';

        return;
    }

    creatingAppointment.value = true;
    createError.value = '';

    try {
        await request<{ id: number }>('/schedule/appointments', 'POST', {
            barbershop_id: shop.value.id,
            name: clientName.value.trim(),
            email: clientEmail.value.trim(),
            phone: clientPhone.value.trim(),
            notes: clientNotes.value.trim() || null,
            service_ids: createServiceIds.value,
            barber_id: createBarber.value.id,
            date: createDate.value,
            time: createTime.value,
            ...(selectedRangeMinutes.value !== null
                ? { selected_duration: selectedRangeMinutes.value }
                : {}),
        });
        toast.success('Agendamento criado.');
        createAppointmentOpen.value = false;
        calendar.value?.getApi().gotoDate(`${createDate.value}T12:00:00`);
        refresh();
    } catch (error) {
        createError.value = message(error);
        toast.error(createError.value);
    } finally {
        creatingAppointment.value = false;
    }
}
function showBlock(
    selectionStart?: string,
    selectionEnd?: string,
    allDay = false,
) {
    formError.value = '';
    editingBlock.value = null;
    reason.value = '';
    const currentDate = calendar.value?.getApi().getDate();
    const now = new Date();
    const today = localTime(now.toISOString()).slice(0, 10);
    const calendarDay = currentDate
        ? localTime(currentDate.toISOString()).slice(0, 10)
        : today;
    const day = calendarDay < today ? today : calendarDay;
    const nextMinute = new Date(
        Math.floor(now.getTime() / 60_000) * 60_000 + 60_000,
    );
    start.value =
        selectionStart ??
        (day === today ? localTime(nextMinute.toISOString()) : `${day}T09:00`);
    end.value =
        selectionEnd ??
        (day === today
            ? localTime(
                  new Date(nextMinute.getTime() + 60 * 60_000).toISOString(),
              )
            : `${day}T10:00`);
    blockDate.value = start.value.slice(0, 10);
    fullDay.value = allDay;
    blockBarbers.value = barberId.value
        ? [Number(barberId.value)]
        : shop.value!.barbers.length === 1
          ? [shop.value!.barbers[0].id]
          : [];
    blockOpen.value = true;
}
function toggleAllBarbers(event: Event) {
    if (event.target instanceof HTMLInputElement && shop.value) {
        blockBarbers.value = event.target.checked
            ? shop.value.barbers.map((barber) => barber.id)
            : [];
    }
}
function openEvent(event: EventApi) {
    selected.value = eventSelection(event);
    start.value = selected.value.start;
    end.value = selected.value.end;
    formError.value = '';
    detailsOpen.value = true;
}
async function openCheckout() {
    if (!selected.value?.details.canCheckout) {
        return;
    }

    checkoutProducts.value = [];
    productToAdd.value = '';
    discount.value = '';
    paymentMethod.value = '';
    checkoutNotes.value = '';
    checkoutError.value = '';
    detailsOpen.value = false;
    await nextTick();
    checkoutOpen.value = true;
}
function addCheckoutProduct() {
    const id = Number(productToAdd.value);

    const product = shop.value?.products.find((item) => item.id === id);

    if (
        product &&
        product.stock_quantity > 0 &&
        !checkoutProducts.value.some((item) => item.id === id)
    ) {
        checkoutProducts.value.push({ id, quantity: 1 });
    }

    productToAdd.value = '';
}
function limitCheckoutQuantity(item: { id: number; quantity: number }) {
    const stock = shop.value?.products.find(
        (product) => product.id === item.id,
    )?.stock_quantity;

    if (stock !== undefined) {
        item.quantity = Math.max(1, Math.min(item.quantity || 1, stock, 999));
    }
}
async function submitCheckout() {
    if (!selected.value || !shop.value || busy.value || !validDiscount.value) {
        return;
    }

    busy.value = true;
    checkoutError.value = '';

    try {
        await request(
            `/schedule/appointments/${selected.value.details.recordId}/checkout`,
            'POST',
            {
                barbershop_id: shop.value.id,
                version: selected.value.details.version,
                services_total_cents: serviceTotalCents.value,
                services: (selected.value.details.services ?? []).map(
                    (service) => ({
                        id: service.id,
                        price_cents: Math.round(Number(service.price) * 100),
                    }),
                ),
                products: checkoutLines.value.map((item) => ({
                    id: item.id,
                    quantity: item.quantity,
                    price_cents: Math.round(Number(item.product?.price) * 100),
                })),
                discount_cents: discountCents.value,
                payment_method: paymentMethod.value,
                notes: checkoutNotes.value.trim() || null,
            },
        );
        checkoutOpen.value = false;
        toast.success('Atendimento finalizado.');
        refresh();
    } catch (error) {
        checkoutError.value = message(error);
        toast.error(checkoutError.value);
    } finally {
        busy.value = false;
    }
}
function editBlock() {
    if (!selected.value) {
        return;
    }

    editingBlock.value = selected.value.details.recordId;
    blockBarbers.value = [selected.value.details.barberId];
    start.value = selected.value.start;
    end.value = selected.value.end;
    reason.value = selected.value.details.reason ?? '';
    blockDate.value = start.value.slice(0, 10);
    fullDay.value = false;
    formError.value = '';
    detailsOpen.value = false;
    blockOpen.value = true;
}
async function mutate(
    url: string,
    method: string,
    data: Record<string, unknown>,
    success: string,
) {
    busy.value = true;
    formError.value = '';

    try {
        await request(url, method, { ...data, barbershop_id: shopId.value });
        toast.success(success);
        detailsOpen.value = false;
        blockOpen.value = false;
        confirmOpen.value = false;
        refresh();
    } catch (error) {
        formError.value = message(error);
        toast.error(formError.value);
    } finally {
        busy.value = false;
    }
}
function saveAppointment() {
    if (!selected.value) {
        return;
    }

    return mutate(
        `/schedule/appointments/${selected.value.details.recordId}`,
        'PATCH',
        {
            start: start.value,
            end: end.value,
            status: 'scheduled',
            version: selected.value.details.version,
        },
        'Agendamento reagendado.',
    );
}
function saveBlock() {
    return mutate(
        editingBlock.value
            ? `/schedule/blocks/${editingBlock.value}`
            : '/schedule/blocks',
        editingBlock.value ? 'PATCH' : 'POST',
        {
            barber_ids: blockBarbers.value,
            reason: reason.value,
            full_day: fullDay.value,
            date: fullDay.value ? blockDate.value : null,
            start: start.value,
            end: end.value,
        },
        'Bloqueio salvo.',
    );
}
function confirm(action: 'cancel' | 'delete' | 'no-show') {
    confirmAction.value = action;
    formError.value = '';
    detailsOpen.value = false;
    confirmOpen.value = true;
}
function executeConfirmed() {
    if (!selected.value) {
        return;
    }

    const id = selected.value.details.recordId;

    if (confirmAction.value === 'no-show') {
        return mutate(
            `/schedule/appointments/${id}/no-show`,
            'POST',
            {
                version: selected.value.details.version,
            },
            'Não comparecimento registrado.',
        );
    }

    return confirmAction.value === 'cancel'
        ? mutate(
              `/schedule/appointments/${id}`,
              'PATCH',
              {
                  status: 'cancelled',
                  version: selected.value.details.version,
              },
              'Agendamento cancelado.',
          )
        : mutate(`/schedule/blocks/${id}`, 'DELETE', {}, 'Bloqueio removido.');
}
async function moveEvent(info: { event: EventApi; revert: () => void }) {
    if (busy.value) {
        info.revert();

        return;
    }

    const selection = eventSelection(info.event);
    busy.value = true;

    try {
        const details = selection.details;
        const appointment = details.kind === 'appointment';
        await request(
            `/schedule/${appointment ? 'appointments' : 'blocks'}/${details.recordId}`,
            'PATCH',
            {
                barbershop_id: shopId.value,
                start: selection.start,
                end: selection.end,
                ...(appointment
                    ? { status: 'scheduled', version: details.version }
                    : {
                          barber_ids: [details.barberId],
                          reason: details.reason,
                      }),
            },
        );
        toast.success(
            appointment ? 'Agendamento reagendado.' : 'Bloqueio atualizado.',
        );
        refresh();
    } catch (error) {
        info.revert();
        toast.error(message(error));
    } finally {
        busy.value = false;
    }
}

const options = computed<CalendarOptions>(() => ({
    plugins: [
        dayGridPlugin,
        timeGridPlugin,
        listPlugin,
        interactionPlugin,
        luxonPlugin,
    ],
    locale: ptBrLocale,
    timeZone: DISPLAY_TIMEZONE,
    initialDate: shop.value?.today,
    initialView:
        typeof window !== 'undefined' &&
        window.matchMedia('(max-width: 640px)').matches
            ? 'timeGridDay'
            : 'timeGridWeek',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
    },
    buttonText: {
        today: 'Hoje',
        month: 'Mês',
        week: 'Semana',
        day: 'Dia',
        list: 'Lista',
    },
    height: 720,
    nowIndicator: true,
    allDaySlot: false,
    slotDuration: '00:15:00',
    snapDuration: '00:05:00',
    slotMinTime: '00:00:00',
    slotMaxTime: '24:00:00',
    scrollTime: '08:00:00',
    dayMaxEvents: 3,
    eventDisplay: 'block',
    eventInteractive: true,
    editable: !busy.value,
    selectable: !busy.value && Boolean(shop.value?.barbers.length),
    selectAllow: (selection) =>
        !isPastSelection({
            start: selection.allDay
                ? `${selection.startStr.slice(0, 10)}T00:00`
                : localTime(selection.start.toISOString()),
            end: selection.endStr,
            startsAt: selection.start.getTime(),
            allDay: selection.allDay,
            durationMinutes: null,
        }),
    eventAllow: (selection) => selection.start.getTime() > Date.now(),
    selectMirror: true,
    longPressDelay: 400,
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    events: async (range, success, failure) => {
        loadError.value = '';

        try {
            const params = new URLSearchParams({
                barbershop_id: String(shopId.value),
                start: range.startStr,
                end: range.endStr,
                ...(barberId.value ? { barber_id: barberId.value } : {}),
            });
            const events = await request<EventInput[]>(
                `/schedule/events?${params}`,
            );
            success(
                events.map((event) => ({
                    ...event,
                    backgroundColor:
                        event.extendedProps?.kind === 'block'
                            ? '#52525b'
                            : event.extendedProps?.status === 'completed'
                              ? '#1e3a8a'
                              : event.extendedProps?.status === 'no_show'
                                ? '#b91c1c'
                                : '#047857',
                    borderColor:
                        event.extendedProps?.kind === 'block'
                            ? '#52525b'
                            : event.extendedProps?.status === 'completed'
                              ? '#1e3a8a'
                              : event.extendedProps?.status === 'no_show'
                                ? '#b91c1c'
                                : '#047857',
                    classNames:
                        event.extendedProps?.kind === 'block'
                            ? ['schedule-block']
                            : [],
                    durationEditable: event.extendedProps?.kind === 'block',
                })),
            );
        } catch (error) {
            loadError.value = message(error);
            failure(
                error instanceof Error ? error : new Error(loadError.value),
            );
        }
    },
    loading: (value) => {
        loading.value = value;
    },
    eventClick: (info) => {
        if (!busy.value) {
            openEvent(info.event);
        }
    },
    select: (info) => {
        const selection = info.allDay
            ? {
                  start: `${info.startStr}T00:00`,
                  end: `${info.endStr}T00:00`,
                  startsAt: info.start.getTime(),
                  allDay: true,
                  durationMinutes: null,
              }
            : {
                  start: localTime(info.start.toISOString()),
                  end: localTime(info.end.toISOString()),
                  startsAt: info.start.getTime(),
                  allDay: false,
                  durationMinutes: Math.round(
                      (info.end.getTime() - info.start.getTime()) / 60_000,
                  ),
              };

        if (props.canManageAppointments) {
            calendarSelection.value = selection;
            rangeChoiceOpen.value = true;
        } else {
            showBlock(selection.start, selection.end, selection.allDay);
        }

        calendar.value?.getApi().unselect();
    },
    eventDrop: moveEvent,
    eventResize: moveEvent,
}));
</script>

<template>
    <Head title="Agendamentos" />
    <div class="min-w-0 space-y-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">Agendamentos</h1>
            <p class="text-sm text-muted-foreground">
                Selecione uma unidade para consultar e organizar a agenda.
            </p>
        </div>
        <section aria-label="Unidades" class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="font-medium">Unidades</h2>
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Unidades anteriores"
                        @click="
                            carousel?.scrollBy({
                                left: -320,
                                behavior: 'smooth',
                            })
                        "
                        ><ChevronLeft
                    /></Button>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Próximas unidades"
                        @click="
                            carousel?.scrollBy({
                                left: 320,
                                behavior: 'smooth',
                            })
                        "
                        ><ChevronRight
                    /></Button>
                </div>
            </div>
            <div
                ref="carousel"
                class="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2"
            >
                <button
                    v-for="unit in barbershops"
                    :key="unit.id"
                    type="button"
                    :disabled="busy"
                    :aria-pressed="shopId === unit.id"
                    class="w-64 shrink-0 snap-start overflow-hidden rounded-xl border bg-card text-left transition focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        shopId === unit.id
                            ? 'border-primary ring-2 ring-primary'
                            : 'hover:border-primary/50'
                    "
                    @click="selectShop(unit)"
                >
                    <img
                        v-if="unit.photo"
                        :src="unit.photo"
                        alt=""
                        class="h-24 w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-24 items-center justify-center bg-muted"
                    >
                        <CalendarDays class="size-8 text-muted-foreground" />
                    </div>
                    <div class="space-y-1 p-4">
                        <p class="font-semibold">{{ unit.name }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ unit.address || 'Unidade da Barbearia 85' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ unit.barbers.length }} barbeiro(s)
                        </p>
                    </div>
                </button>
            </div>
            <p
                v-if="!barbershops.length"
                class="rounded-lg border p-6 text-muted-foreground"
            >
                Nenhuma unidade associada ao seu perfil.
            </p>
        </section>
        <section v-if="shop" class="space-y-4" :aria-busy="loading || busy">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ shop.name }}</h2>
                    <p class="text-xs text-muted-foreground">
                        Bloqueios valem em todas as unidades do barbeiro.
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <Button
                        v-if="canManageAppointments"
                        :disabled="
                            busy ||
                            loading ||
                            !shop.is_open ||
                            !shop.barbers.length
                        "
                        @click="openCreateAppointment"
                        ><Plus class="size-4" />Novo agendamento</Button
                    >
                    <div v-if="canManageAppointments" class="space-y-1">
                        <Label for="barber-filter">Barbeiro</Label>
                        <select
                            id="barber-filter"
                            v-model="barberId"
                            :class="selectClass"
                            :disabled="busy"
                            @change="refresh"
                        >
                            <option value="">Toda a equipe</option>
                            <option
                                v-for="barber in shop.barbers"
                                :key="barber.id"
                                :value="String(barber.id)"
                            >
                                {{ barber.name }}
                            </option>
                        </select>
                    </div>
                    <Button
                        variant="outline"
                        :disabled="busy || loading"
                        @click="refresh"
                        >Atualizar</Button
                    >
                    <Button
                        :disabled="busy || !shop.barbers.length"
                        @click="showBlock()"
                        ><LockKeyhole class="size-4" />Bloquear agenda</Button
                    >
                    <Button
                        variant="outline"
                        :disabled="busy || !shop.barbers.length"
                        @click="showBlock(undefined, undefined, true)"
                        >Fechar o dia</Button
                    >
                </div>
            </div>
            <div class="flex flex-wrap gap-3 text-xs" aria-label="Legenda">
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-2.5 rounded-full bg-[#047857]"></span
                    >Agendamento</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-2.5 rounded-full bg-[#1e3a8a]"></span
                    >Concluído</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-2.5 rounded-full bg-[#b91c1c]"></span>Não
                    compareceu (No Show)</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-2.5 rounded-full bg-zinc-600"></span
                    >Bloqueio</span
                >
            </div>
            <p class="text-sm text-muted-foreground">
                Clique em uma reserva para ver os detalhes.
                <span v-if="canManageAppointments">
                    Selecione uma faixa para criar um agendamento ou bloqueio.
                </span>
                <span v-else
                    >Selecione um intervalo para criar um bloqueio.</span
                >
                <span v-if="canManageAppointments"
                    >Arraste para reagendar; ajuste a borda inferior para
                    alterar a duração.</span
                >
            </p>
            <p
                v-if="loading"
                role="status"
                class="text-sm text-muted-foreground"
            >
                Carregando agenda…
            </p>
            <div
                v-if="loadError"
                role="alert"
                class="rounded-lg border border-destructive p-4 text-sm text-destructive"
            >
                {{ loadError }}
                <Button variant="outline" class="ml-2" @click="refresh"
                    >Tentar novamente</Button
                >
            </div>
            <div class="schedule-calendar rounded-xl border bg-card p-3">
                <FullCalendar
                    :key="shop.id"
                    ref="calendar"
                    :options="options"
                />
            </div>
        </section>
        <div
            v-else-if="barbershops.length"
            class="rounded-xl border border-dashed p-12 text-center text-muted-foreground"
        >
            <CalendarDays class="mx-auto mb-3 size-10" />
            Escolha uma unidade acima para carregar o calendário.
        </div>

        <Dialog v-model:open="rangeChoiceOpen">
            <DialogContent
                class="sm:max-w-md"
                @interact-outside="busy && $event.preventDefault()"
                @escape-key-down="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>O que deseja criar?</DialogTitle>
                    <DialogDescription>
                        Faixa selecionada:
                        {{ calendarSelection?.start.replace('T', ' ') }}–{{
                            calendarSelection?.end.replace('T', ' ')
                        }}
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Button
                        :disabled="
                            busy ||
                            !shop?.is_open ||
                            !shop?.barbers.length ||
                            isPastSelection(calendarSelection)
                        "
                        @click="chooseCalendarAction('appointment')"
                    >
                        <Plus class="size-4" />
                        Novo agendamento
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="
                            busy ||
                            !shop?.barbers.length ||
                            isPastSelection(calendarSelection)
                        "
                        @click="chooseCalendarAction('block')"
                    >
                        <LockKeyhole class="size-4" />
                        Bloqueio
                    </Button>
                </div>
                <p
                    v-if="isPastSelection(calendarSelection)"
                    class="text-sm text-muted-foreground"
                >
                    Não é possível criar agendamentos ou bloqueios no passado.
                    Selecione uma data e um horário futuros.
                </p>
                <p v-if="!shop?.is_open" class="text-sm text-muted-foreground">
                    A unidade está fechada; ainda é possível criar um bloqueio
                    de agenda.
                </p>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="createAppointmentOpen">
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                @interact-outside="
                    creatingAppointment && $event.preventDefault()
                "
                @escape-key-down="
                    creatingAppointment && $event.preventDefault()
                "
            >
                <DialogHeader>
                    <DialogTitle>Novo agendamento</DialogTitle>
                    <DialogDescription>
                        Registre uma reserva recebida por telefone, WhatsApp ou
                        outro canal.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="selectedRangeMinutes !== null"
                    class="rounded-md border bg-muted/50 p-3 text-sm"
                >
                    Faixa selecionada:
                    {{ calendarSelection?.start.replace('T', ' ') }}–{{
                        calendarSelection?.end.replace('T', ' ')
                    }}
                    ({{ selectedRangeMinutes }} min). O agendamento ocupará
                    apenas a duração do serviço.
                </p>
                <form
                    class="space-y-5"
                    @submit.prevent="submitCreateAppointment"
                >
                    <fieldset
                        class="space-y-4"
                        :disabled="creatingAppointment || !shop?.is_open"
                    >
                        <legend class="text-sm font-semibold">
                            Dados do cliente
                        </legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2 sm:col-span-2">
                                <Label for="manual-client-name">Nome</Label>
                                <Input
                                    id="manual-client-name"
                                    v-model="clientName"
                                    autocomplete="name"
                                    maxlength="255"
                                    required
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="manual-client-phone"
                                    >Telefone</Label
                                >
                                <PhoneInput
                                    id="manual-client-phone"
                                    v-model="clientPhone"
                                    required
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="manual-client-email">E-mail</Label>
                                <Input
                                    id="manual-client-email"
                                    v-model="clientEmail"
                                    type="email"
                                    autocomplete="email"
                                    maxlength="255"
                                    required
                                />
                            </div>
                            <div class="space-y-2 sm:col-span-2">
                                <Label for="manual-client-notes"
                                    >Observações (opcional)</Label
                                >
                                <textarea
                                    id="manual-client-notes"
                                    v-model="clientNotes"
                                    maxlength="5000"
                                    rows="2"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    placeholder="Preferências ou informações importantes"
                                />
                            </div>
                        </div>
                    </fieldset>
                    <fieldset
                        class="space-y-4 border-t pt-4"
                        :disabled="creatingAppointment || !shop?.is_open"
                    >
                        <legend class="text-sm font-semibold">
                            Serviço e horário
                        </legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="manual-barber">Barbeiro</Label>
                                <select
                                    id="manual-barber"
                                    v-model="createBarberId"
                                    required
                                    :class="`${selectClass} w-full`"
                                >
                                    <option value="" disabled>
                                        Selecione um barbeiro
                                    </option>
                                    <option
                                        v-for="barber in shop?.barbers"
                                        :key="barber.id"
                                        :value="String(barber.id)"
                                    >
                                        {{ barber.name }}
                                    </option>
                                </select>
                            </div>
                            <fieldset class="space-y-2 sm:col-span-2">
                                <legend class="text-sm font-medium">
                                    Serviços
                                </legend>
                                <label
                                    v-for="service in creationServices"
                                    :key="service.id"
                                    class="flex cursor-pointer items-center gap-3 rounded-md border p-3"
                                    :class="
                                        createServiceIds.includes(service.id)
                                            ? 'border-primary bg-primary/5'
                                            : 'border-input'
                                    "
                                >
                                    <input
                                        v-model="createServiceIds"
                                        type="checkbox"
                                        :value="service.id"
                                        :disabled="!createBarberId"
                                    />
                                    <span class="flex-1">{{
                                        service.name
                                    }}</span>
                                    <span class="text-sm text-muted-foreground">
                                        {{ service.duration }} min ·
                                        {{
                                            currency.format(
                                                Number(service.price),
                                            )
                                        }}
                                    </span>
                                </label>
                                <p
                                    v-if="
                                        createBarberId &&
                                        !creationServices.length
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Este barbeiro não possui serviços ativos
                                    nesta unidade.
                                </p>
                                <p
                                    v-if="selectedCreationServices.length"
                                    class="text-sm font-medium"
                                >
                                    Total: {{ createDuration }} min ·
                                    {{ currency.format(createPrice) }}
                                </p>
                            </fieldset>
                            <div class="space-y-2">
                                <Label for="manual-date">Data</Label>
                                <Input
                                    id="manual-date"
                                    v-model="createDate"
                                    type="date"
                                    :min="shop?.today"
                                    :max="latestDate"
                                    required
                                />
                                <p class="text-xs text-muted-foreground">
                                    Agendamentos disponíveis até 45 dias a
                                    partir de hoje.
                                </p>
                            </div>
                            <div class="space-y-2">
                                <Label for="manual-period">Período</Label>
                                <select
                                    id="manual-period"
                                    v-model="createPeriod"
                                    required
                                    :class="`${selectClass} w-full`"
                                >
                                    <option value="morning">
                                        Manhã · antes das 12h
                                    </option>
                                    <option value="afternoon">
                                        Tarde · das 12h às 18h
                                    </option>
                                    <option value="evening">
                                        Noite · a partir das 18h
                                    </option>
                                </select>
                            </div>
                            <div class="space-y-2 sm:col-span-2">
                                <Label for="manual-time"
                                    >Horário disponível</Label
                                >
                                <select
                                    id="manual-time"
                                    v-model="createTime"
                                    required
                                    :disabled="
                                        slotsLoading || !creationSlots.length
                                    "
                                    :class="`${selectClass} w-full`"
                                >
                                    <option value="" disabled>
                                        {{
                                            slotsLoading
                                                ? 'Buscando horários…'
                                                : !createBarberId ||
                                                    !createServiceIds.length
                                                  ? 'Selecione barbeiro e serviço primeiro'
                                                  : 'Selecione um horário'
                                        }}
                                    </option>
                                    <option
                                        v-for="slot in creationSlots"
                                        :key="slot.time"
                                        :value="slot.time"
                                    >
                                        {{ slot.time }}
                                    </option>
                                </select>
                                <p
                                    v-if="
                                        createDate &&
                                        createServiceIds.length &&
                                        !slotsLoading &&
                                        !slotsError &&
                                        !creationSlots.length
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Nenhum horário disponível neste período.
                                </p>
                                <p
                                    v-if="slotsError"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ slotsError }}
                                </p>
                                <p
                                    v-if="selectedRangeTooShort"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    A faixa selecionada tem
                                    {{ selectedRangeMinutes }} min, menos que a
                                    duração total dos serviços ({{
                                        createDuration
                                    }}
                                    min). Selecione um intervalo maior ou
                                    escolha outros serviços.
                                </p>
                            </div>
                        </div>
                    </fieldset>
                    <p
                        v-if="!shop?.is_open"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        Esta unidade está fechada e não aceita agendamentos.
                    </p>
                    <p
                        v-if="createError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ createError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="creatingAppointment"
                            @click="createAppointmentOpen = false"
                            >Voltar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                creatingAppointment ||
                                !shop?.is_open ||
                                !createTime ||
                                !createServiceIds.length ||
                                selectedRangeTooShort
                            "
                            >{{
                                creatingAppointment
                                    ? 'Salvando…'
                                    : 'Confirmar agendamento'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="detailsOpen">
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
                @interact-outside="busy && $event.preventDefault()"
                @escape-key-down="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        selected?.details.kind === 'block'
                            ? 'Bloqueio de agenda'
                            : 'Detalhes do agendamento'
                    }}</DialogTitle>
                    <DialogDescription
                        >{{ selected?.details.barberName }} ·
                        {{ shop?.name }}</DialogDescription
                    >
                </DialogHeader>
                <template v-if="selected">
                    <dl
                        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm [&_dd]:min-w-0 [&_dd]:[overflow-wrap:anywhere]"
                    >
                        <template v-if="selected.details.client">
                            <dt class="text-muted-foreground">Cliente</dt>
                            <dd>{{ selected.details.client.name }}</dd>
                            <dt class="text-muted-foreground">Telefone</dt>
                            <dd>{{ selected.details.client.phone }}</dd>
                            <dt class="text-muted-foreground">E-mail</dt>
                            <dd class="break-all">
                                {{ selected.details.client.email }}
                            </dd>
                        </template>
                        <template v-if="selected.details.checkout">
                            <dt class="text-muted-foreground">Serviços</dt>
                            <dd class="space-y-1">
                                <div
                                    v-for="service in selected.details.checkout
                                        .services"
                                    :key="service.id"
                                >
                                    {{ service.name }} ·
                                    {{ service.duration }} min ·
                                    {{
                                        currency.format(
                                            service.price_cents / 100,
                                        )
                                    }}
                                </div>
                            </dd>
                            <template
                                v-if="selected.details.checkout.products.length"
                            >
                                <dt class="text-muted-foreground">Produtos</dt>
                                <dd class="space-y-1">
                                    <div
                                        v-for="product in selected.details
                                            .checkout.products"
                                        :key="product.id"
                                    >
                                        {{ product.name }} ·
                                        {{ product.brand }} ·
                                        {{ product.quantity }} ×
                                        {{
                                            currency.format(
                                                product.price_cents / 100,
                                            )
                                        }}
                                    </div>
                                </dd>
                            </template>
                            <dt class="text-muted-foreground">
                                Total de serviços
                            </dt>
                            <dd>
                                {{
                                    currency.format(
                                        selected.details.checkout
                                            .services_total_cents / 100,
                                    )
                                }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Total de produtos
                            </dt>
                            <dd>
                                {{
                                    currency.format(
                                        selected.details.checkout
                                            .products_total_cents / 100,
                                    )
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Desconto</dt>
                            <dd>
                                {{
                                    currency.format(
                                        selected.details.checkout
                                            .discount_cents / 100,
                                    )
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Total</dt>
                            <dd>
                                {{
                                    currency.format(
                                        selected.details.checkout.total_cents /
                                            100,
                                    )
                                }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Forma de pagamento
                            </dt>
                            <dd>
                                {{
                                    paymentLabels[
                                        selected.details.checkout.payment_method
                                    ]
                                }}
                            </dd>
                            <template v-if="selected.details.checkout.notes">
                                <dt class="text-muted-foreground">
                                    Observações do checkout
                                </dt>
                                <dd class="whitespace-pre-wrap">
                                    {{ selected.details.checkout.notes }}
                                </dd>
                            </template>
                        </template>
                        <template v-else-if="selected.details.services?.length">
                            <dt class="text-muted-foreground">Serviços</dt>
                            <dd class="space-y-1">
                                <div
                                    v-for="service in selected.details.services"
                                    :key="service.name"
                                >
                                    {{ service.name }} ·
                                    {{ service.duration }} min ·
                                    {{ currency.format(Number(service.price)) }}
                                </div>
                            </dd>
                        </template>
                        <template v-if="selected.details.status"
                            ><dt class="text-muted-foreground">Situação</dt>
                            <dd>
                                {{ statusLabels[selected.details.status] }}
                            </dd></template
                        >
                        <dt class="text-muted-foreground">Início</dt>
                        <dd>{{ selected.start.replace('T', ' ') }}</dd>
                        <dt class="text-muted-foreground">Fim</dt>
                        <dd>{{ selected.end.replace('T', ' ') }}</dd>
                        <template
                            v-if="
                                selected.details.notes ||
                                selected.details.reason
                            "
                            ><dt class="text-muted-foreground">Observações</dt>
                            <dd>
                                {{
                                    selected.details.notes ||
                                    selected.details.reason
                                }}
                            </dd></template
                        >
                    </dl>
                    <form
                        v-if="
                            canManageAppointments &&
                            selected.details.kind === 'appointment' &&
                            selected.details.status === 'scheduled'
                        "
                        class="space-y-4 border-t pt-4"
                        @submit.prevent="saveAppointment"
                    >
                        <div class="space-y-2">
                            <Label for="appointment-start">Novo início</Label
                            ><Input
                                id="appointment-start"
                                v-model="start"
                                type="datetime-local"
                                required
                                :disabled="busy"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="appointment-end">Novo fim</Label
                            ><Input
                                id="appointment-end"
                                v-model="end"
                                type="datetime-local"
                                required
                                :disabled="busy"
                            />
                        </div>
                        <p
                            v-if="formError"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ formError }}
                        </p>
                        <div class="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="destructive"
                                class="h-auto min-h-9 min-w-0 px-2 whitespace-normal"
                                :disabled="busy"
                                @click="confirm('cancel')"
                                >Cancelar agendamento</Button
                            >
                            <Button
                                type="submit"
                                class="h-auto min-h-9 min-w-0 px-2 whitespace-normal"
                                :disabled="busy"
                                >{{
                                    busy ? 'Salvando…' : 'Salvar alterações'
                                }}</Button
                            >
                            <Button
                                v-if="selected.details.canCheckout"
                                type="button"
                                variant="outline"
                                class="col-span-2 h-auto min-h-9 whitespace-normal"
                                :disabled="busy"
                                @click="openCheckout"
                                >Finalizar atendimento</Button
                            >
                        </div>
                    </form>
                    <Button
                        v-if="canMarkNoShow"
                        type="button"
                        variant="destructive"
                        :disabled="busy"
                        @click="confirm('no-show')"
                        >NÃO COMPARECEU</Button
                    >
                    <p
                        v-if="selected.details.checkoutUnavailableReason"
                        role="status"
                        class="border-t pt-4 text-sm text-muted-foreground"
                    >
                        {{ selected.details.checkoutUnavailableReason }}
                        <a
                            :href="`/cash-register?barbershop_id=${shop?.id}`"
                            class="font-medium text-primary underline"
                        >
                            Abrir caixa
                        </a>
                    </p>
                    <DialogFooter v-if="selected.details.kind === 'block'">
                        <Button
                            variant="destructive"
                            :disabled="busy"
                            @click="confirm('delete')"
                            >Remover bloqueio</Button
                        >
                        <Button :disabled="busy" @click="editBlock"
                            >Editar bloqueio</Button
                        >
                    </DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="checkoutOpen">
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
                :show-close-button="!busy"
                @interact-outside="busy && $event.preventDefault()"
                @escape-key-down="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>Finalizar atendimento</DialogTitle>
                    <DialogDescription>
                        {{ selected?.details.client?.name }} · {{ shop?.name }}.
                        O pagamento é realizado presencialmente; o sistema
                        apenas registra a forma escolhida.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitCheckout">
                    <fieldset class="space-y-2" :disabled="busy">
                        <legend class="text-sm font-semibold">
                            Total de serviços
                        </legend>
                        <div
                            v-for="(service, index) in selected?.details
                                .services"
                            :key="index"
                            class="flex justify-between gap-3 text-sm"
                        >
                            <span>{{ service.name }}</span
                            ><span>{{
                                currency.format(Number(service.price))
                            }}</span>
                        </div>
                        <p class="text-right text-sm font-medium">
                            {{ currency.format(serviceTotalCents / 100) }}
                        </p>
                    </fieldset>
                    <fieldset class="space-y-2 border-t pt-4" :disabled="busy">
                        <legend class="text-sm font-semibold">Produtos</legend>
                        <div class="flex gap-2">
                            <select
                                v-model="productToAdd"
                                :class="`${selectClass} min-w-0 flex-1`"
                                aria-label="Selecionar produto"
                            >
                                <option value="">Selecione um produto</option>
                                <option
                                    v-for="product in shop?.products.filter(
                                        (product) =>
                                            product.stock_quantity > 0 &&
                                            !checkoutProducts.some(
                                                (item) =>
                                                    item.id === product.id,
                                            ),
                                    )"
                                    :key="product.id"
                                    :value="String(product.id)"
                                >
                                    {{ product.name }} · {{ product.brand }} ·
                                    {{ currency.format(Number(product.price)) }}
                                </option>
                            </select>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    !productToAdd ||
                                    checkoutProducts.length >= 100
                                "
                                @click="addCheckoutProduct"
                                >Adicionar</Button
                            >
                        </div>
                        <p
                            v-if="
                                !shop?.products.some(
                                    (product) => product.stock_quantity > 0,
                                )
                            "
                            class="text-sm text-muted-foreground"
                        >
                            Nenhum produto disponível em estoque nesta unidade.
                        </p>
                        <div
                            v-for="item in checkoutProducts"
                            :key="item.id"
                            class="flex flex-wrap items-center gap-2 text-sm"
                        >
                            <span class="min-w-0 flex-1"
                                >{{
                                    shop?.products.find(
                                        (product) => product.id === item.id,
                                    )?.name
                                }}
                                ·
                                {{
                                    currency.format(
                                        Number(
                                            shop?.products.find(
                                                (product) =>
                                                    product.id === item.id,
                                            )?.price,
                                        ),
                                    )
                                }}
                                · Em estoque:
                                {{
                                    shop?.products.find(
                                        (product) => product.id === item.id,
                                    )?.stock_quantity
                                }}</span
                            >
                            <Label :for="`quantity-${item.id}`">Qtd.</Label>
                            <Input
                                :id="`quantity-${item.id}`"
                                v-model.number="item.quantity"
                                class="w-20"
                                type="number"
                                min="1"
                                :max="
                                    Math.min(
                                        shop?.products.find(
                                            (product) => product.id === item.id,
                                        )?.stock_quantity ?? 1,
                                        999,
                                    )
                                "
                                step="1"
                                required
                                @change="limitCheckoutQuantity(item)"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                @click="
                                    checkoutProducts = checkoutProducts.filter(
                                        (line) => line.id !== item.id,
                                    )
                                "
                                >Remover</Button
                            >
                        </div>
                        <p class="text-right text-sm font-medium">
                            Total de produtos:
                            {{ currency.format(productTotalCents / 100) }}
                        </p>
                    </fieldset>
                    <div class="space-y-2">
                        <Label for="checkout-discount"
                            >Desconto em reais (opcional)</Label
                        >
                        <Input
                            id="checkout-discount"
                            v-model="discount"
                            type="number"
                            min="0"
                            step="0.01"
                            :disabled="busy"
                        />
                        <p
                            v-if="!validDiscount"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            Informe um desconto válido que não supere o
                            subtotal.
                        </p>
                    </div>
                    <p class="text-lg font-semibold">
                        Valor total:
                        {{
                            currency.format(
                                (subtotalCents -
                                    (validDiscount ? discountCents : 0)) /
                                    100,
                            )
                        }}
                    </p>
                    <div class="space-y-2">
                        <Label for="checkout-payment">Forma de pagamento</Label>
                        <select
                            id="checkout-payment"
                            v-model="paymentMethod"
                            required
                            :disabled="busy"
                            :class="`${selectClass} w-full`"
                        >
                            <option value="" disabled>
                                Selecione a forma de pagamento
                            </option>
                            <option
                                v-for="(label, value) in paymentLabels"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label for="checkout-notes"
                            >Observações (opcional)</Label
                        >
                        <textarea
                            id="checkout-notes"
                            v-model="checkoutNotes"
                            maxlength="5000"
                            rows="3"
                            :disabled="busy"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <p
                        v-if="checkoutError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ checkoutError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="busy"
                            @click="
                                checkoutOpen = false;
                                detailsOpen = true;
                            "
                            >Voltar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="busy || !validDiscount"
                            >{{ busy ? 'Salvando…' : 'Confirmar' }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="blockOpen">
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
                @interact-outside="busy && $event.preventDefault()"
                @escape-key-down="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editingBlock
                            ? 'Editar bloqueio'
                            : fullDay
                              ? 'Fechar agenda do dia'
                              : 'Bloquear agenda'
                    }}</DialogTitle>
                    <DialogDescription
                        >Não é possível bloquear intervalos com reservas. O
                        bloqueio impede novas reservas em todas as unidades do
                        profissional.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="saveBlock">
                    <fieldset
                        class="space-y-2"
                        :disabled="busy || Boolean(editingBlock)"
                    >
                        <legend class="mb-2 text-sm font-medium">
                            Barbeiros
                        </legend>
                        <label
                            v-if="canManageAppointments && !editingBlock"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                :checked="
                                    Boolean(shop?.barbers.length) &&
                                    blockBarbers.length === shop?.barbers.length
                                "
                                @change="toggleAllBarbers"
                            />Toda a equipe
                        </label>
                        <label
                            v-for="barber in shop?.barbers"
                            :key="barber.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="blockBarbers"
                                type="checkbox"
                                :value="barber.id"
                            />{{ barber.name }}
                        </label>
                    </fieldset>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="fullDay"
                            type="checkbox"
                            :disabled="busy"
                        />Fechar o dia inteiro</label
                    >
                    <div v-if="fullDay" class="space-y-2">
                        <Label for="block-date">Dia</Label
                        ><Input
                            id="block-date"
                            v-model="blockDate"
                            type="date"
                            :min="
                                localTime(new Date().toISOString()).slice(0, 10)
                            "
                            required
                            :disabled="busy"
                        />
                        <p class="text-xs text-muted-foreground">
                            Ao fechar o dia de hoje, o bloqueio começa agora e
                            termina no fim do dia.
                        </p>
                    </div>
                    <template v-else>
                        <div class="space-y-2">
                            <Label for="block-start">Início</Label
                            ><Input
                                id="block-start"
                                v-model="start"
                                type="datetime-local"
                                :min="localTime(new Date().toISOString())"
                                required
                                :disabled="busy"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="block-end">Fim</Label
                            ><Input
                                id="block-end"
                                v-model="end"
                                type="datetime-local"
                                required
                                :disabled="busy"
                            />
                        </div>
                    </template>
                    <div class="space-y-2">
                        <Label for="block-reason">Motivo</Label
                        ><Input
                            id="block-reason"
                            v-model="reason"
                            maxlength="255"
                            required
                            :disabled="busy"
                            placeholder="Ex.: almoço, férias, manutenção"
                        />
                    </div>
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formError }}
                    </p>
                    <DialogFooter
                        ><Button
                            type="submit"
                            :disabled="busy || !blockBarbers.length"
                            >{{
                                busy ? 'Salvando…' : 'Salvar bloqueio'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="confirmOpen">
            <DialogContent
                @interact-outside="busy && $event.preventDefault()"
                @escape-key-down="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        confirmAction === 'cancel'
                            ? 'Cancelar este agendamento?'
                            : confirmAction === 'no-show'
                              ? 'Registrar não comparecimento?'
                              : 'Remover este bloqueio?'
                    }}</DialogTitle>
                    <DialogDescription>{{
                        confirmAction === 'cancel'
                            ? 'A reserva será cancelada e o horário ficará disponível para novos agendamentos.'
                            : confirmAction === 'no-show'
                              ? 'Confirme que o cliente não compareceu. O atendimento será registrado como falta, sem gerar fechamento ou cobrança.'
                              : 'O intervalo voltará a aceitar agendamentos em todas as unidades do barbeiro.'
                    }}</DialogDescription>
                </DialogHeader>
                <p
                    v-if="formError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ formError }}
                </p>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="busy"
                        @click="confirmOpen = false"
                        >Voltar</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="busy"
                        @click="executeConfirmed"
                        >{{ busy ? 'Salvando…' : 'Confirmar' }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
.schedule-calendar {
    --fc-border-color: var(--border);
    --fc-page-bg-color: var(--card);
    --fc-neutral-bg-color: var(--muted);
    --fc-list-event-hover-bg-color: var(--muted);
    --fc-today-bg-color: color-mix(in srgb, var(--primary) 8%, transparent);
    --fc-button-bg-color: var(--primary);
    --fc-button-border-color: var(--primary);
    --fc-button-text-color: var(--primary-foreground);
    --fc-button-hover-bg-color: color-mix(in srgb, var(--primary) 85%, black);
    --fc-button-hover-border-color: var(--primary);
    --fc-button-active-bg-color: color-mix(in srgb, var(--primary) 75%, black);
    --fc-button-active-border-color: var(--primary);
    --fc-event-text-color: #fff;
}
.schedule-calendar :deep(.fc-event) {
    cursor: pointer;
}
.schedule-calendar :deep(.schedule-block) {
    background-image: repeating-linear-gradient(
        135deg,
        transparent,
        transparent 6px,
        #ffffff15 6px,
        #ffffff15 12px
    );
}
.schedule-calendar :deep(.fc-toolbar-title) {
    font-size: 1.1rem;
}
.schedule-calendar :deep(.fc-button) {
    font-size: 0.8rem;
}
.schedule-calendar :deep(.fc-timegrid-slot) {
    height: 1.5rem;
}
@media (max-width: 767px) {
    .schedule-calendar :deep(.fc-header-toolbar) {
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .schedule-calendar :deep(.fc-toolbar-chunk:nth-child(2)) {
        order: -1;
        width: 100%;
    }
}
</style>

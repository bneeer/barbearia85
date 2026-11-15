<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/money';

type Method = 'cash' | 'pix' | 'credit_card' | 'debit_card';
type Amounts = Record<Method, number>;
type Withdrawal = {
    id: number;
    amount_cents: number;
    reason: string;
    user: string;
    created_at: string;
};
type CurrentRegister = {
    id: number;
    opened_at: string;
    opened_by: string;
    opening_amount_cents: number;
    sales_cents: Amounts;
    expense_payments_cents: Amounts;
    expected_cents: Amounts;
    checkout_count: number;
    withdrawals_total_cents: number;
    withdrawals: Withdrawal[];
    expense_payments: {
        amount_cents: number;
        payment_method: Method;
        expense_title: string;
        user: string;
        paid_at: string;
    }[];
};
type ClosedRegister = {
    id: number;
    opened_at: string;
    closed_at: string;
    opened_by: string;
    closed_by: string;
    opening_amount_cents: number;
    closure: {
        expected: Amounts;
        counted: Amounts;
        difference: Amounts;
    };
    expense_payments_cents: Amounts;
    closing_note: string | null;
    withdrawals: Omit<Withdrawal, 'id'>[];
};

const props = defineProps<{
    barbershops: { id: number; name: string; timezone: string }[];
    selectedBarbershopId: number | null;
    current: CurrentRegister | null;
    history: ClosedRegister[];
}>();

const methods: { key: Method; label: string }[] = [
    { key: 'cash', label: 'Dinheiro' },
    { key: 'pix', label: 'PIX' },
    { key: 'credit_card', label: 'Crédito' },
    { key: 'debit_card', label: 'Débito' },
];
const selectedId = computed(() => props.selectedBarbershopId?.toString() ?? '');
const selectedTimezone = computed(
    () =>
        props.barbershops.find(
            (barbershop) => barbershop.id === props.selectedBarbershopId,
        )?.timezone ?? 'America/Sao_Paulo',
);
const expensePaymentsTotal = computed(() => {
    const register = props.current;

    return register
        ? methods.reduce(
              (sum, method) =>
                  sum + register.expense_payments_cents[method.key],
              0,
          )
        : 0;
});
const openForm = useForm({
    barbershop_id: props.selectedBarbershopId ?? 0,
    opening_amount: '0.00',
});
const withdrawalForm = useForm({
    barbershop_id: props.selectedBarbershopId ?? 0,
    amount: '',
    reason: '',
});
const closeForm = useForm({
    barbershop_id: props.selectedBarbershopId ?? 0,
    counted_cash: '',
    counted_pix: '',
    counted_credit_card: '',
    counted_debit_card: '',
    closing_note: '',
});
const busy = computed(
    () =>
        openForm.processing ||
        withdrawalForm.processing ||
        closeForm.processing,
);
defineOptions({
    layout: { breadcrumbs: [{ title: 'Caixa', href: '/cash-register' }] },
});

watch(
    () => props.selectedBarbershopId,
    (id) => {
        openForm.barbershop_id = id ?? 0;
        withdrawalForm.barbershop_id = id ?? 0;
        closeForm.barbershop_id = id ?? 0;
    },
);

function selectShop(event: Event) {
    const id = (event.target as HTMLSelectElement).value;
    router.get('/cash-register', { barbershop_id: id }, { replace: true });
}

function date(value: string) {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: selectedTimezone.value,
    }).format(new Date(value));
}

function submitOpen() {
    openForm.post('/cash-register', {
        preserveScroll: true,
        onSuccess: () => openForm.reset('opening_amount'),
    });
}

function submitWithdrawal() {
    if (!props.current) {
        return;
    }

    withdrawalForm.post(`/cash-register/${props.current.id}/withdrawals`, {
        preserveScroll: true,
        onSuccess: () => withdrawalForm.reset('amount', 'reason'),
    });
}

function submitClose() {
    if (!props.current) {
        return;
    }

    closeForm.post(`/cash-register/${props.current.id}/close`, {
        preserveScroll: true,
        onSuccess: () => closeForm.reset(),
    });
}
</script>

<template>
    <Head title="Caixa" />
    <div class="space-y-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold">Caixa</h1>
                <p class="text-sm text-muted-foreground">
                    Abertura, sangrias e conferência por forma de pagamento.
                </p>
            </div>
            <div class="w-full space-y-2 sm:max-w-xs">
                <Label for="cash-register-shop">Unidade</Label>
                <select
                    id="cash-register-shop"
                    :value="selectedId"
                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                    @change="selectShop"
                >
                    <option value="" disabled>Selecione uma unidade</option>
                    <option
                        v-for="barbershop in barbershops"
                        :key="barbershop.id"
                        :value="barbershop.id"
                    >
                        {{ barbershop.name }}
                    </option>
                </select>
            </div>
        </div>

        <template v-if="selectedBarbershopId">
            <Card v-if="!current">
                <CardHeader>
                    <CardTitle>Abrir caixa</CardTitle>
                    <CardDescription>
                        Informe o valor inicial em dinheiro. A unidade só pode
                        ter um caixa aberto por vez.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form
                        class="max-w-sm space-y-4"
                        @submit.prevent="submitOpen"
                    >
                        <div class="space-y-2">
                            <Label for="opening-amount"
                                >Saldo inicial (R$)</Label
                            >
                            <Input
                                id="opening-amount"
                                v-model="openForm.opening_amount"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                                :disabled="busy"
                            />
                            <InputError
                                :message="openForm.errors.opening_amount"
                            />
                            <InputError
                                :message="openForm.errors.barbershop_id"
                            />
                        </div>
                        <Button type="submit" :disabled="busy">
                            {{
                                openForm.processing ? 'Abrindo…' : 'Abrir caixa'
                            }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <template v-else>
                <Card>
                    <CardHeader>
                        <CardTitle>Caixa aberto</CardTitle>
                        <CardDescription>
                            Aberto por {{ current.opened_by }} em
                            {{ date(current.opened_at) }} ·
                            {{ current.checkout_count }} atendimento(s)
                            finalizado(s).
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-md border p-3">
                                <p class="text-sm text-muted-foreground">
                                    Fundo inicial
                                </p>
                                <p class="text-lg font-semibold">
                                    {{
                                        formatMoney(
                                            current.opening_amount_cents,
                                        )
                                    }}
                                </p>
                            </div>
                            <div
                                v-for="method in methods"
                                :key="method.key"
                                class="rounded-md border p-3"
                            >
                                <p class="text-sm text-muted-foreground">
                                    {{ method.label }} · vendas
                                </p>
                                <p class="text-lg font-semibold">
                                    {{
                                        formatMoney(
                                            current.sales_cents[method.key],
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Despesas:
                                    {{
                                        formatMoney(
                                            current.expense_payments_cents[
                                                method.key
                                            ],
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Esperado:
                                    {{
                                        formatMoney(
                                            current.expected_cents[method.key],
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="rounded-md border p-3">
                                <p class="text-sm text-muted-foreground">
                                    Total de sangrias
                                </p>
                                <p class="text-lg font-semibold">
                                    {{
                                        formatMoney(
                                            current.withdrawals_total_cents,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="rounded-md border p-3">
                                <p class="text-sm text-muted-foreground">
                                    Pagamentos de despesas pelo caixa
                                </p>
                                <p class="text-lg font-semibold">
                                    {{ formatMoney(expensePaymentsTotal) }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-6 lg:grid-cols-2">
                            <form
                                class="space-y-4 rounded-md border p-4"
                                @submit.prevent="submitWithdrawal"
                            >
                                <div>
                                    <h2 class="font-semibold">
                                        Registrar sangria
                                    </h2>
                                    <p class="text-sm text-muted-foreground">
                                        Retirada de dinheiro do caixa; o motivo
                                        fica registrado no histórico.
                                    </p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="withdrawal-amount"
                                        >Valor (R$)</Label
                                    >
                                    <Input
                                        id="withdrawal-amount"
                                        v-model="withdrawalForm.amount"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        required
                                        :disabled="busy"
                                    />
                                    <InputError
                                        :message="withdrawalForm.errors.amount"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <Label for="withdrawal-reason"
                                        >Motivo</Label
                                    >
                                    <Input
                                        id="withdrawal-reason"
                                        v-model="withdrawalForm.reason"
                                        maxlength="255"
                                        required
                                        :disabled="busy"
                                    />
                                    <InputError
                                        :message="withdrawalForm.errors.reason"
                                    />
                                </div>
                                <Button type="submit" :disabled="busy">
                                    {{
                                        withdrawalForm.processing
                                            ? 'Registrando…'
                                            : 'Registrar sangria'
                                    }}
                                </Button>
                            </form>

                            <form
                                class="space-y-4 rounded-md border p-4"
                                @submit.prevent="submitClose"
                            >
                                <div>
                                    <h2 class="font-semibold">
                                        Conferir e fechar
                                    </h2>
                                    <p class="text-sm text-muted-foreground">
                                        Informe os valores contados/confirmados
                                        por forma de pagamento.
                                    </p>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div
                                        v-for="method in methods"
                                        :key="method.key"
                                        class="space-y-2"
                                    >
                                        <Label :for="`counted-${method.key}`">
                                            {{ method.label }} (R$)
                                        </Label>
                                        <Input
                                            :id="`counted-${method.key}`"
                                            v-model="
                                                closeForm[
                                                    `counted_${method.key}`
                                                ]
                                            "
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            required
                                            :disabled="busy"
                                        />
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Esperado:
                                            {{
                                                formatMoney(
                                                    current.expected_cents[
                                                        method.key
                                                    ],
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <Label for="closing-note">
                                        Justificativa da divergência (se houver)
                                    </Label>
                                    <textarea
                                        id="closing-note"
                                        v-model="closeForm.closing_note"
                                        rows="3"
                                        maxlength="5000"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        :disabled="busy"
                                    />
                                    <InputError
                                        :message="closeForm.errors.closing_note"
                                    />
                                </div>
                                <Button type="submit" :disabled="busy">
                                    {{
                                        closeForm.processing
                                            ? 'Fechando…'
                                            : 'Fechar caixa'
                                    }}
                                </Button>
                            </form>
                        </div>

                        <section>
                            <h2 class="mb-3 font-semibold">
                                Sangrias desta sessão
                            </h2>
                            <p
                                v-if="current.withdrawals.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Nenhuma sangria registrada.
                            </p>
                            <div v-else class="divide-y rounded-md border">
                                <div
                                    v-for="withdrawal in current.withdrawals"
                                    :key="withdrawal.id"
                                    class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <span
                                        >{{ withdrawal.reason }} ·
                                        {{ withdrawal.user }}</span
                                    >
                                    <span class="text-muted-foreground">
                                        {{
                                            formatMoney(withdrawal.amount_cents)
                                        }}
                                        ·
                                        {{ date(withdrawal.created_at) }}
                                    </span>
                                </div>
                            </div>
                        </section>
                    </CardContent>
                </Card>
            </template>

            <Card>
                <CardHeader>
                    <CardTitle>Fechamentos recentes</CardTitle>
                    <CardDescription>
                        Conferências e divergências registradas nos últimos
                        caixas.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="history.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Ainda não há caixas fechados nesta unidade.
                    </p>
                    <div v-else class="space-y-4">
                        <article
                            v-for="register in history"
                            :key="register.id"
                            class="space-y-2 rounded-md border p-4"
                        >
                            <div
                                class="flex flex-col justify-between gap-1 sm:flex-row"
                            >
                                <h2 class="font-medium">
                                    Caixa #{{ register.id }} ·
                                    {{ date(register.opened_at) }}–{{
                                        date(register.closed_at)
                                    }}
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    Aberto por {{ register.opened_by }} ·
                                    fechado por {{ register.closed_by }}
                                </p>
                            </div>
                            <div
                                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <div
                                    v-for="method in methods"
                                    :key="method.key"
                                    class="text-sm"
                                >
                                    <p class="font-medium">
                                        {{ method.label }}
                                    </p>
                                    <p class="text-muted-foreground">
                                        Esperado:
                                        {{
                                            formatMoney(
                                                register.closure.expected[
                                                    method.key
                                                ],
                                            )
                                        }}
                                        · Conferido:
                                        {{
                                            formatMoney(
                                                register.closure.counted[
                                                    method.key
                                                ],
                                            )
                                        }}
                                    </p>
                                    <p>
                                        Diferença:
                                        {{
                                            formatMoney(
                                                register.closure.difference[
                                                    method.key
                                                ],
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="register.closing_note"
                                class="text-sm text-muted-foreground"
                            >
                                Justificativa: {{ register.closing_note }}
                            </p>
                            <div
                                v-if="register.withdrawals.length"
                                class="space-y-1 border-t pt-2 text-sm"
                            >
                                <p class="font-medium">Sangrias</p>
                                <p
                                    v-for="(
                                        withdrawal, index
                                    ) in register.withdrawals"
                                    :key="`${register.id}-${index}`"
                                    class="text-muted-foreground"
                                >
                                    {{ withdrawal.reason }} ·
                                    {{ formatMoney(withdrawal.amount_cents) }} ·
                                    {{ withdrawal.user }} ·
                                    {{ date(withdrawal.created_at) }}
                                </p>
                            </div>
                        </article>
                    </div>
                </CardContent>
            </Card>
        </template>
        <p v-else class="text-sm text-muted-foreground">
            Cadastre uma unidade para operar o caixa.
        </p>
    </div>
</template>

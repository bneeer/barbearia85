<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Summary = {
    revenue_cents: number;
    expenses_cents: number;
    expense_count: number;
    expense_payments_cents: number;
    product_cost_cents: number;
    supply_cost_cents: number;
    commission_cents: number;
    salary_base_cents: number;
    estimated_profit_cents: number | null;
    cash_flow_net_cents: number;
    checkout_count: number;
    incomplete_checkout_count: number;
    barbers: {
        id: number;
        name: string;
        commission_cents: number;
        incomplete_checkout_count: number;
    }[];
};
type Expense = {
    id: number;
    title: string;
    category: string;
    amount_cents: number;
    paid_cents: number;
    remaining_cents: number;
    competence_date: string;
    due_date: string;
    recurring: boolean;
    payments: {
        amount_cents: number;
        payment_method: string;
        paid_at: string;
        user: string;
    }[];
};
type Recurrence = {
    id: number;
    title: string;
    category: string;
    amount_cents: number;
    due_day: number;
    starts_on: string;
    ends_on: string | null;
    active: boolean;
};

const props = defineProps<{
    barbershops: { id: number; name: string; timezone: string }[];
    selectedBarbershopId: number | null;
    filters: { from: string; to: string };
    summary: Summary | null;
    expenses: Expense[];
    recurrences: Recurrence[];
    openCashRegisterId: number | null;
}>();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const selectedExpense = ref<Expense | null>(null);
const paymentOpen = ref(false);
const formatMoney = (cents: number | null) =>
    cents === null
        ? 'Indisponível'
        : new Intl.NumberFormat('pt-BR', {
              style: 'currency',
              currency: 'BRL',
          }).format(cents / 100);
const expenseForm = useForm({
    barbershop_id: props.selectedBarbershopId ?? 0,
    title: '',
    category: '',
    amount: '',
    competence_date: props.filters.to,
    due_date: props.filters.to,
});
const recurrenceForm = useForm({
    barbershop_id: props.selectedBarbershopId ?? 0,
    title: '',
    category: '',
    amount: '',
    due_day: 10,
    starts_on: props.filters.to,
    ends_on: '',
});
const paymentForm = useForm({
    amount: '',
    payment_method: 'cash',
    use_open_cash: false,
    notes: '',
});
watch(
    () => props.selectedBarbershopId,
    (id) => {
        expenseForm.barbershop_id = id ?? 0;
        recurrenceForm.barbershop_id = id ?? 0;
    },
);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Financeiro', href: '/finance' }] },
});

function filter(shopId = props.selectedBarbershopId) {
    router.get(
        '/finance',
        {
            barbershop_id: shopId ?? '',
            from: from.value,
            to: to.value,
        },
        { preserveState: true, replace: true },
    );
}

function submitExpense() {
    expenseForm.post('/finance/expenses', {
        preserveScroll: true,
        onSuccess: () => expenseForm.reset('title', 'category', 'amount'),
    });
}

function submitRecurrence() {
    recurrenceForm.post('/finance/recurrences', {
        preserveScroll: true,
        onSuccess: () =>
            recurrenceForm.reset('title', 'category', 'amount', 'ends_on'),
    });
}

function openPayment(expense: Expense) {
    selectedExpense.value = expense;
    paymentForm.reset();
    paymentForm.amount = (expense.remaining_cents / 100).toFixed(2);
    paymentForm.payment_method = 'cash';
    paymentForm.use_open_cash = false;
    paymentOpen.value = true;
}

function submitPayment() {
    if (!selectedExpense.value) {
        return;
    }

    paymentForm.post(`/finance/expenses/${selectedExpense.value.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            paymentOpen.value = false;
            selectedExpense.value = null;
            paymentForm.reset();
        },
    });
}

function toggleRecurrence(recurrence: Recurrence) {
    router.patch(
        `/finance/recurrences/${recurrence.id}`,
        { active: !recurrence.active },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Despesas e Faturamento" />
    <div class="space-y-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold">Despesas e Faturamento</h1>
                <p class="text-sm text-muted-foreground">
                    Estimativas por competência e pagamentos registrados; não
                    representam confirmação bancária ou escrituração contábil.
                </p>
            </div>
            <div class="w-full space-y-2 sm:max-w-xs">
                <Label for="finance-shop">Unidade</Label>
                <select
                    id="finance-shop"
                    :value="selectedBarbershopId ?? ''"
                    class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    @change="
                        filter(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="shop in barbershops"
                        :key="shop.id"
                        :value="shop.id"
                    >
                        {{ shop.name }}
                    </option>
                </select>
            </div>
        </div>

        <template v-if="summary">
            <form
                class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_1fr_auto]"
                @submit.prevent="filter()"
            >
                <label class="grid gap-1 text-sm font-medium">
                    De
                    <Input v-model="from" type="date" required />
                </label>
                <label class="grid gap-1 text-sm font-medium">
                    Até
                    <Input v-model="to" type="date" required />
                </label>
                <Button class="self-end" type="submit">Aplicar período</Button>
            </form>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader
                        ><CardTitle
                            >Faturamento registrado</CardTitle
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-xl font-semibold">
                            {{ formatMoney(summary.revenue_cents) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ summary.checkout_count }} checkout(s)
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle
                            >Despesas por competência</CardTitle
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-xl font-semibold">
                            {{ formatMoney(summary.expenses_cents) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ summary.expense_count }} lançamento(s)
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle
                            >Custos de produtos e insumos</CardTitle
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-xl font-semibold">
                            {{
                                formatMoney(
                                    summary.product_cost_cents +
                                        summary.supply_cost_cents,
                                )
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Produtos
                            {{ formatMoney(summary.product_cost_cents) }} ·
                            Insumos {{ formatMoney(summary.supply_cost_cents) }}
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle>Lucro estimado</CardTitle></CardHeader
                    >
                    <CardContent>
                        <p class="text-xl font-semibold">
                            {{ formatMoney(summary.estimated_profit_cents) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Comissões
                            {{ formatMoney(summary.commission_cents) }} ·
                            Salários-base
                            {{ formatMoney(summary.salary_base_cents) }}
                        </p>
                    </CardContent>
                </Card>
            </section>

            <Card>
                <CardHeader
                    ><CardTitle
                        >Fluxo de pagamentos do período</CardTitle
                    ></CardHeader
                >
                <CardContent class="space-y-1">
                    <p>
                        Pagamentos de despesas registrados:
                        <strong>{{
                            formatMoney(summary.expense_payments_cents)
                        }}</strong>
                    </p>
                    <p>
                        Checkouts registrados menos pagamentos de despesas:
                        <strong>{{
                            formatMoney(summary.cash_flow_net_cents)
                        }}</strong>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Checkouts refletem os registros da equipe; não confirmam
                        processamento ou liquidação do pagamento.
                    </p>
                    <p
                        v-if="summary.incomplete_checkout_count"
                        role="status"
                        class="text-sm text-amber-700"
                    >
                        {{ summary.incomplete_checkout_count }} checkout(s) sem
                        algum snapshot de custo/comissão. O lucro estimado foi
                        omitido para evitar presumir custo zero.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>Comissões por barbeiro</CardTitle></CardHeader
                >
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b">
                                    <th class="p-2">Barbeiro</th>
                                    <th class="p-2">Comissão</th>
                                    <th class="p-2">Dados incompletos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="barber in summary.barbers"
                                    :key="barber.id"
                                    class="border-b"
                                >
                                    <td class="p-2">{{ barber.name }}</td>
                                    <td class="p-2">
                                        {{
                                            formatMoney(barber.commission_cents)
                                        }}
                                    </td>
                                    <td class="p-2">
                                        {{ barber.incomplete_checkout_count }}
                                    </td>
                                </tr>
                                <tr v-if="!summary.barbers.length">
                                    <td
                                        colspan="3"
                                        class="p-2 text-muted-foreground"
                                    >
                                        Sem checkouts no período.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <section class="grid gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader
                        ><CardTitle>Lançar despesa</CardTitle></CardHeader
                    >
                    <CardContent>
                        <form
                            class="grid gap-3"
                            @submit.prevent="submitExpense"
                        >
                            <input
                                v-model="expenseForm.barbershop_id"
                                type="hidden"
                            />
                            <div class="grid gap-1">
                                <Label for="expense-title">Descrição</Label
                                ><Input
                                    id="expense-title"
                                    v-model="expenseForm.title"
                                    maxlength="255"
                                    required
                                /><InputError
                                    :message="expenseForm.errors.title"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label for="expense-category">Categoria</Label
                                ><Input
                                    id="expense-category"
                                    v-model="expenseForm.category"
                                    maxlength="100"
                                    placeholder="Aluguel, energia, manutenção..."
                                    required
                                /><InputError
                                    :message="expenseForm.errors.category"
                                />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="grid gap-1 text-sm font-medium"
                                    >Valor (R$)<Input
                                        v-model="expenseForm.amount"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        required /><InputError
                                        :message="expenseForm.errors.amount"
                                /></label>
                                <label class="grid gap-1 text-sm font-medium"
                                    >Competência<Input
                                        v-model="expenseForm.competence_date"
                                        type="date"
                                        required /><InputError
                                        :message="
                                            expenseForm.errors.competence_date
                                        "
                                /></label>
                                <label class="grid gap-1 text-sm font-medium"
                                    >Vencimento<Input
                                        v-model="expenseForm.due_date"
                                        type="date"
                                        required /><InputError
                                        :message="expenseForm.errors.due_date"
                                /></label>
                            </div>
                            <InputError
                                :message="expenseForm.errors.barbershop_id"
                            />
                            <Button
                                type="submit"
                                :disabled="expenseForm.processing"
                                >Cadastrar despesa</Button
                            >
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        ><CardTitle
                            >Nova despesa recorrente mensal</CardTitle
                        ></CardHeader
                    >
                    <CardContent>
                        <form
                            class="grid gap-3"
                            @submit.prevent="submitRecurrence"
                        >
                            <input
                                v-model="recurrenceForm.barbershop_id"
                                type="hidden"
                            />
                            <div class="grid gap-1">
                                <Label for="recurrence-title">Descrição</Label
                                ><Input
                                    id="recurrence-title"
                                    v-model="recurrenceForm.title"
                                    maxlength="255"
                                    required
                                /><InputError
                                    :message="recurrenceForm.errors.title"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label for="recurrence-category"
                                    >Categoria</Label
                                ><Input
                                    id="recurrence-category"
                                    v-model="recurrenceForm.category"
                                    maxlength="100"
                                    required
                                /><InputError
                                    :message="recurrenceForm.errors.category"
                                />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="grid gap-1 text-sm font-medium"
                                    >Valor mensal (R$)<Input
                                        v-model="recurrenceForm.amount"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        required /><InputError
                                        :message="recurrenceForm.errors.amount"
                                /></label>
                                <label class="grid gap-1 text-sm font-medium"
                                    >Dia do vencimento<Input
                                        v-model.number="recurrenceForm.due_day"
                                        type="number"
                                        min="1"
                                        max="31"
                                        required /><InputError
                                        :message="
                                            recurrenceForm.errors.due_day
                                        "
                                /></label>
                                <label class="grid gap-1 text-sm font-medium"
                                    >Início<Input
                                        v-model="recurrenceForm.starts_on"
                                        type="date"
                                        required /><InputError
                                        :message="
                                            recurrenceForm.errors.starts_on
                                        "
                                /></label>
                            </div>
                            <label class="grid gap-1 text-sm font-medium"
                                >Encerrar em (opcional)<Input
                                    v-model="recurrenceForm.ends_on"
                                    type="date" /><InputError
                                    :message="recurrenceForm.errors.ends_on"
                            /></label>
                            <InputError
                                :message="recurrenceForm.errors.barbershop_id"
                            />
                            <Button
                                type="submit"
                                :disabled="recurrenceForm.processing"
                                >Criar recorrência</Button
                            >
                        </form>
                    </CardContent>
                </Card>
            </section>

            <Card>
                <CardHeader><CardTitle>Contas a pagar</CardTitle></CardHeader>
                <CardContent class="space-y-3">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b">
                                    <th class="p-2">Despesa</th>
                                    <th class="p-2">
                                        Competência / vencimento
                                    </th>
                                    <th class="p-2">Valor</th>
                                    <th class="p-2">Pago / pendente</th>
                                    <th class="p-2">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="expense in expenses"
                                    :key="expense.id"
                                    class="border-b"
                                >
                                    <td class="p-2">
                                        <p class="font-medium">
                                            {{ expense.title }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ expense.category
                                            }}{{
                                                expense.recurring
                                                    ? ' · recorrente'
                                                    : ''
                                            }}
                                        </p>
                                    </td>
                                    <td class="p-2">
                                        {{ expense.competence_date }}<br />{{
                                            expense.due_date
                                        }}
                                    </td>
                                    <td class="p-2">
                                        {{ formatMoney(expense.amount_cents) }}
                                    </td>
                                    <td class="p-2">
                                        {{ formatMoney(expense.paid_cents) }} /
                                        {{
                                            formatMoney(expense.remaining_cents)
                                        }}
                                    </td>
                                    <td class="p-2">
                                        <Button
                                            v-if="expense.remaining_cents > 0"
                                            size="sm"
                                            variant="outline"
                                            @click="openPayment(expense)"
                                            >Registrar pagamento</Button
                                        ><span
                                            v-else
                                            class="text-sm text-muted-foreground"
                                            >Quitada</span
                                        >
                                    </td>
                                </tr>
                                <tr v-if="!expenses.length">
                                    <td
                                        colspan="5"
                                        class="p-2 text-muted-foreground"
                                    >
                                        Nenhuma despesa cadastrada.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Recorrências</CardTitle></CardHeader>
                <CardContent class="space-y-2">
                    <div
                        v-for="recurrence in recurrences"
                        :key="recurrence.id"
                        class="flex flex-wrap items-center justify-between gap-3 border-b py-2"
                    >
                        <div>
                            <p class="font-medium">
                                {{ recurrence.title }} ·
                                {{ formatMoney(recurrence.amount_cents) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ recurrence.category }} · vence dia
                                {{ recurrence.due_day }} · desde
                                {{ recurrence.starts_on
                                }}{{
                                    recurrence.ends_on
                                        ? ` até ${recurrence.ends_on}`
                                        : ''
                                }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="toggleRecurrence(recurrence)"
                            >{{
                                recurrence.active ? 'Pausar' : 'Ativar'
                            }}</Button
                        >
                    </div>
                    <p
                        v-if="!recurrences.length"
                        class="text-sm text-muted-foreground"
                    >
                        Nenhuma recorrência cadastrada.
                    </p>
                </CardContent>
            </Card>
        </template>
        <p v-else class="rounded-md border p-4 text-sm text-muted-foreground">
            Cadastre ou associe uma unidade para consultar o financeiro.
        </p>

        <div
            v-if="paymentOpen && selectedExpense"
            class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
            role="presentation"
            @click.self="paymentOpen = false"
        >
            <Card class="w-full max-w-lg">
                <CardHeader
                    ><CardTitle>Registrar pagamento</CardTitle></CardHeader
                >
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submitPayment">
                        <p class="text-sm">
                            {{ selectedExpense.title }} · pendente
                            {{ formatMoney(selectedExpense.remaining_cents) }}
                        </p>
                        <label class="grid gap-1 text-sm font-medium"
                            >Valor pago (R$)<Input
                                v-model="paymentForm.amount"
                                type="number"
                                min="0.01"
                                :max="selectedExpense.remaining_cents / 100"
                                step="0.01"
                                required /><InputError
                                :message="paymentForm.errors.amount"
                        /></label>
                        <label class="grid gap-1 text-sm font-medium"
                            >Forma de pagamento
                            <select
                                v-model="paymentForm.payment_method"
                                class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="cash">Dinheiro</option>
                                <option value="pix">PIX</option>
                                <option value="credit_card">Crédito</option>
                                <option value="debit_card">Débito</option>
                            </select>
                        </label>
                        <label
                            v-if="openCashRegisterId"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="paymentForm.use_open_cash"
                                type="checkbox"
                            />Registrar como saída do caixa aberto da
                            unidade</label
                        >
                        <InputError
                            :message="paymentForm.errors.use_open_cash"
                        />
                        <label class="grid gap-1 text-sm font-medium"
                            >Observação (opcional)<Input
                                v-model="paymentForm.notes"
                                maxlength="500"
                        /></label>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                @click="paymentOpen = false"
                                >Cancelar</Button
                            ><Button
                                type="submit"
                                :disabled="paymentForm.processing"
                                >Confirmar pagamento</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>

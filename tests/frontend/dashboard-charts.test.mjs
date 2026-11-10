import assert from 'node:assert/strict';
import path from 'node:path';
import { after, test } from 'node:test';
import vue from '@vitejs/plugin-vue';
import { renderToString } from '@vue/server-renderer';
import { createServer } from 'vite';
import {
    createRenderer,
    createSSRApp,
    h,
    nextTick,
    reactive,
    ssrContextKey,
} from 'vue';

const server = await createServer({
    configFile: false,
    plugins: [vue()],
    resolve: { alias: { '@': path.resolve('resources/js') } },
    optimizeDeps: { noDiscovery: true, include: [] },
    server: { middlewareMode: true },
    appType: 'custom',
});
after(() => server.close());

const BarberChart = (
    await server.ssrLoadModule(
        path.resolve('resources/js/components/BarberAppointmentsChart.vue'),
    )
).default;
const PaymentChart = (
    await server.ssrLoadModule(
        path.resolve('resources/js/components/PaymentMethodsChart.vue'),
    )
).default;
const renderer = createRenderer({
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    insert() {},
    remove() {},
    patchProp() {},
});

function mount(t, component, props) {
    const headless = { ...component, render: () => null };
    const app = renderer.createApp({
        render: () => h(headless, { ...props }),
    });
    app.provide(ssrContextKey, {});
    app.mount({});
    t.after(() => app.unmount());

    return app._instance.subTree.component.setupState;
}

const barber = (id, name, completed, scheduled) => ({
    id,
    name,
    current: { completed, scheduled },
});
const payment = (total_cents) => ({ total_cents, count: 1 });
const render = (component, props) =>
    renderToString(createSSRApp({ render: () => h(component, props) }));

test('horizontal bars use completed and scheduled counts, rank totals and react to filters', async (t) => {
    const props = reactive({
        barbers: [
            barber(1, 'Ana', 2, 1),
            barber(2, 'Bruno', 3, 5),
            barber(3, 'Sem movimento', 0, 0),
        ],
    });
    const state = mount(t, BarberChart, props);
    assert.equal(state.options.indexAxis, 'y');
    assert.equal(state.options.scales.x.ticks.precision, 0);
    assert.deepEqual(state.data.labels, ['Bruno (#2)', 'Ana (#1)']);
    assert.deepEqual(
        state.data.datasets.map((item) => item.data),
        [
            [3, 2],
            [5, 1],
        ],
    );
    assert.deepEqual(
        props.barbers.map((item) => item.id),
        [1, 2, 3],
    );

    props.barbers = [barber(4, 'Ana', 1, 0), barber(1, 'Ana', 1, 0)];
    await nextTick();
    assert.deepEqual(state.data.labels, ['Ana (#1)', 'Ana (#4)']);
    props.barbers = [];
    await nextTick();
    assert.deepEqual(state.data.labels, []);
});

test('doughnut uses revenue rather than payment counts and shows fixed total shares', async (t) => {
    const props = reactive({
        paymentMethods: {
            pix: payment(7500),
            cash: payment(2500),
            credit_card: payment(0),
        },
    });
    const state = mount(t, PaymentChart, props);
    assert.equal(state.totalCents, 10000);
    assert.deepEqual(state.data.datasets[0].data, [7500, 2500, 0]);
    assert.deepEqual(state.data.labels, [
        'Pix',
        'Dinheiro',
        'Cartão de crédito',
    ]);
    assert.equal(state.share(7500), '75,0%');
    assert.equal(state.share(2500), '25,0%');
    assert.equal(state.share(0), '0,0%');
    assert.equal(state.share(10000), '100,0%');
    assert.match(
        state.options.plugins.tooltip.callbacks.label({
            label: 'Pix',
            parsed: 7500,
        }),
        /Pix: R\$\s75,00 \(75,0%\)/,
    );

    props.paymentMethods = { debit_card: payment(5000) };
    await nextTick();
    assert.equal(state.totalCents, 5000);
    assert.equal(state.share(5000), '100,0%');
    assert.deepEqual(state.data.labels, ['Cartão de débito']);
});

test('empty and zero revenue do not produce invalid percentages', async (t) => {
    const props = reactive({ paymentMethods: {} });
    const state = mount(t, PaymentChart, props);
    assert.equal(state.totalCents, 0);
    assert.equal(state.share(0), 'Não se aplica');
    props.paymentMethods = { cash: payment(0) };
    await nextTick();
    assert.equal(state.share(0), 'Não se aplica');
    const html = await render(PaymentChart, props);
    assert.match(html, /Nenhum faturamento registrado/);
    assert.match(html, /Não se aplica/);
    assert.doesNotMatch(html, /<canvas|NaN|Infinity/);
});

test('charts provide accessible tables, absolute revenue and percentages without tooltips', async () => {
    const bars = await render(BarberChart, {
        barbers: [barber(1, 'Ana', 2, 3)],
    });
    assert.match(bars, /<canvas[^>]*role="img"/);
    assert.match(bars, /<table/);
    assert.match(bars, /Ana \(#1\)/);
    assert.match(bars, /Concluídos/);
    assert.match(bars, /Agendados/);
    const empty = await render(BarberChart, { barbers: [] });
    assert.match(empty, /Nenhum atendimento concluído ou agendado/);
    assert.doesNotMatch(empty, /<canvas/);
    const donut = await render(PaymentChart, {
        paymentMethods: { pix: payment(7500), cash: payment(2500) },
    });
    assert.match(donut, /<canvas[^>]*role="img"/);
    assert.match(donut, /<table/);
    assert.match(donut, /R\$\s75,00/);
    assert.match(donut, /R\$\s25,00/);
    assert.match(donut, /R\$\s100,00/);
    assert.match(donut, /75,0%/);
    assert.match(donut, /25,0%/);
});

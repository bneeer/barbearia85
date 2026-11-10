import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick } from 'vue';

// Exercise the real setup/watchers without a browser; visual components are not mounted.
const source = await readFile(
    new URL('../../resources/js/pages/Booking.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'booking-stepper-test' })
    .content.replace(
        /^import (.+) from '(?:@\/components\/[^']+|@inertiajs\/vue3)';$/gm,
        (_, bindings) =>
            `const ${bindings} = ${bindings.startsWith('{') ? '{}' : 'null'};`,
    )
    .replaceAll(
        "'@/lib/booking'",
        JSON.stringify(
            new URL('../../resources/js/lib/booking.ts', import.meta.url).href,
        ),
    )
    .replaceAll("'vue'", JSON.stringify(import.meta.resolve('vue')));
const compiled = ts.transpileModule(script, {
    compilerOptions: {
        target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.ESNext,
    },
}).outputText;
const { default: Booking } = await import(
    `data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`
);
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
const shop = {
    id: 1,
    name: 'Centro',
    timezone: 'America/Sao_Paulo',
    today: '2026-11-10',
    address: 'Rua 85',
    phones: [],
    photos: [],
    services: [
        {
            id: 1,
            name: 'Corte',
            duration: 40,
            price: '50.00',
            description: 'Corte completo',
        },
        {
            id: 2,
            name: 'Barba',
            duration: 60,
            price: '35.00',
            description: 'Barba completa',
        },
    ],
    barbers: [
        { id: 7, name: 'Ana', avatar: null, service_ids: [1] },
        { id: 8, name: 'Bruno', avatar: null, service_ids: [2] },
    ],
};
const settle = async () => {
    await nextTick();
    await new Promise(setImmediate);
};
const response = (body, status = 200) => ({
    ok: status < 400,
    status,
    json: async () => body,
});

async function mount(t) {
    const app = renderer.createApp(
        { ...Booking, render: () => null },
        { barbershops: [shop], csrfToken: 'test-token' },
    );
    app.mount({});
    t.after(() => app.unmount());
    const state = app._instance.setupState;
    state.shopId = 1;
    await settle();
    state.serviceIds = [1, 2];
    await settle();

    return state;
}

test('configures disjoint professionals per service, preserves other items and advances to summary', async (t) => {
    const s = await mount(t);
    t.mock.method(globalThis, 'fetch', async (url) => {
        const query = new URL(url, 'http://localhost').searchParams;
        const id = Number(query.get('service_id'));
        assert.equal(query.has('service_ids[0]'), false);

        return response({
            slots: [
                { time: '09:00', barber_ids: [id === 1 ? 7 : 8] },
                { time: '09:40', barber_ids: [id === 1 ? 7 : 8] },
            ],
        });
    });
    s.editItem(1);
    assert.deepEqual(
        s.eligibleBarbers.map((barber) => barber.id),
        [7],
    );
    s.preference = 'any';
    s.date = '2026-11-10';
    s.step = 4;
    await settle();
    s.selectTime(s.slots[0]);
    assert.equal(s.canContinue, false);
    s.barberId = 7;
    assert.equal(s.nextLabel, 'Definir próximo serviço');
    await s.next();
    await settle();
    assert.equal(s.activeServiceId, 2);
    assert.equal(s.step, 3);
    assert.deepEqual(
        s.eligibleBarbers.map((barber) => barber.id),
        [8],
    );
    s.preference = 8;
    s.date = '2026-11-10';
    s.step = 4;
    await settle();
    assert.deepEqual(
        s.slots.map((slot) => slot.time),
        ['09:40'],
    );
    s.selectTime(s.slots[0]);
    await s.next();
    await settle();
    assert.equal(s.step, 5);
    assert.equal(s.allComplete, true);
    assert.match(s.draftDetails(2), /09:40–10:40 · Bruno/);
    assert.equal(s.totalDuration, 100);
    assert.equal(s.totalPrice, 85);
    s.editItem(1);
    await settle();
    assert.equal(s.time, '09:00');
    s.date = '2026-11-11';
    assert.equal(s.time, '');
    assert.equal(s.drafts[1].time, '09:40');
    s.toggleService(1);
    await settle();
    assert.equal(s.drafts.length, 1);
    assert.equal(s.drafts[0].service_id, 2);
    assert.equal(s.drafts[0].barber_id, 8);
    s.shopId = null;
    await settle();
    assert.equal(s.drafts.length, 0);
});

test('a late availability response cannot overwrite a different service or date', async (t) => {
    const s = await mount(t);
    const requests = [];
    t.mock.method(
        globalThis,
        'fetch',
        (url, options) =>
            new Promise((resolve) => {
                requests.push({ url, options, resolve });
            }),
    );
    s.editItem(1);
    s.preference = 'any';
    s.date = '2026-11-10';
    s.step = 4;
    await settle();
    s.editItem(2);
    await settle();
    assert.equal(requests[0].options.signal.aborted, true);
    s.preference = 'any';
    s.date = '2026-11-11';
    s.step = 4;
    await settle();
    requests[1].resolve(
        response({ slots: [{ time: '11:00', barber_ids: [8] }] }),
    );
    await settle();
    requests[0].resolve(
        response({ slots: [{ time: '09:00', barber_ids: [7] }] }),
    );
    await settle();
    assert.equal(s.activeServiceId, 2);
    assert.equal(s.loading, false);
    assert.deepEqual(s.slots, [{ time: '11:00', barber_ids: [8] }]);
    s.date = '2026-11-12';
    await settle();
    assert.deepEqual(s.slots, []);
    assert.equal(s.loading, true);
});

test('batch errors retain other drafts and canonical confirmation produces individual calendar events', async (t) => {
    const s = await mount(t);
    Object.assign(s.drafts[0], {
        preference: 'any',
        barber_id: 7,
        date: '2026-11-10',
        time: '09:00',
    });
    Object.assign(s.drafts[1], {
        preference: 8,
        barber_id: 8,
        date: '2026-11-11',
        time: '09:00',
    });
    s.name = 'Cliente';
    s.email = 'cliente@example.test';
    s.phone = '+5511999999999';
    s.step = 5;
    await settle();
    const appointments = [1, 2].map((id) => ({
        id: id + 100,
        service: shop.services[id - 1],
        barber: shop.barbers[id - 1],
        starts_at: `2026-11-${id === 1 ? '10' : '11'}T12:00:00+00:00`,
        ends_at: `2026-11-${id === 1 ? '10' : '11'}T${id === 1 ? '12:40' : '13:00'}:00+00:00`,
        timezone: 'America/Sao_Paulo',
    }));
    let failed = true;
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        assert.equal(url, '/agendar');
        const body = JSON.parse(options.body);
        assert.equal(body.items.length, 2);
        assert.equal(body.service_ids, undefined);
        assert.equal(body.items[0].preference, undefined);

        return failed
            ? response({ errors: { 'items.1.time': ['Horário ocupado'] } }, 422)
            : response({ appointments }, 201);
    });
    await s.next();
    assert.match(s.error, /Nenhuma reserva foi confirmada/);
    assert.equal(s.drafts[0].time, '09:00');
    assert.equal(s.drafts[1].time, '');
    assert.deepEqual(s.confirmedAppointments, []);
    assert.equal(s.canContinue, false);
    Object.assign(s.drafts[1], { barber_id: 8, time: '09:00' });
    failed = false;
    await s.next();
    assert.deepEqual(s.confirmedAppointments, appointments);

    for (const appointment of appointments) {
        const event = s.calendarEvent(appointment);
        const google = new URL(event.googleUrl).searchParams;
        assert.equal(
            google.get('text'),
            `${appointment.service.name} · Centro`,
        );
        assert.equal(google.get('ctz'), appointment.timezone);
        assert.match(
            event.ics,
            new RegExp(`UID:appointment-${appointment.id}@barbearia85`),
        );
        assert.equal((event.ics.match(/BEGIN:VEVENT/g) ?? []).length, 1);
        const outlook = new URL(event.outlookUrl).searchParams;
        assert.equal(
            outlook.get('startdt'),
            new Date(appointment.starts_at).toISOString(),
        );
        assert.equal(
            outlook.get('enddt'),
            new Date(appointment.ends_at).toISOString(),
        );
    }
});

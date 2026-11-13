import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(
    new URL('../../resources/js/pages/Schedule/Index.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'schedule-no-show' }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: {
        target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.CommonJS,
    },
}).outputText;
const clock = vue.ref(new Date('2026-11-09T12:09:59Z'));
const exports = {};
const requireModule = (name) => {
    if (name === 'vue') {
        return vue;
    }

    if (name === '@vueuse/core') {
        return { useNow: () => clock };
    }

    if (name === 'vue-sonner') {
        return { toast: { success() {}, error() {} } };
    }

    if (name === '@/lib/dateTime') {
        return { DISPLAY_TIMEZONE: 'America/Sao_Paulo' };
    }

    return {};
};
new Function('require', 'exports', compiled)(requireModule, exports);
const renderer = vue.createRenderer({
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

test('no-show becomes visible at the deadline in an open dialog, including for the responsible barber', async (t) => {
    const app = renderer.createApp(
        { ...exports.default, render: () => null },
        {
            barbershops: [
                {
                    id: 1,
                    today: '2026-11-09',
                    barbers: [],
                    services: [],
                    products: [],
                },
            ],
            csrfToken: 'test-token',
            canManageAppointments: false,
        },
    );
    app.mount({});
    t.after(() => app.unmount());
    const state = app._instance.setupState;
    state.selected = {
        start: '2026-11-09T09:00',
        end: '2026-11-09T09:40',
        details: {
            kind: 'appointment',
            recordId: 42,
            status: 'scheduled',
            version: 'v1',
            noShowEligibleAt: '2026-11-09T12:10:00Z',
        },
    };
    state.detailsOpen = true;
    assert.equal(state.canMarkNoShow, false);
    clock.value = new Date('2026-11-09T12:10:00Z');
    assert.equal(state.canMarkNoShow, true);
    state.selected.details.status = 'completed';
    assert.equal(state.canMarkNoShow, false);
    state.selected.details.status = 'scheduled';
    state.selected.details.noShowEligibleAt = null;
    assert.equal(state.canMarkNoShow, false);
    state.selected.details.noShowEligibleAt = '2026-11-09T12:10:00Z';
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        assert.equal(url, '/schedule/appointments/42/no-show');
        assert.equal(options.method, 'POST');
        assert.equal(JSON.parse(options.body).version, 'v1');

        return {
            ok: true,
            json: async () => ({ message: 'Não comparecimento registrado.' }),
        };
    });
    state.confirm('no-show');
    assert.equal(state.detailsOpen, false);
    assert.equal(state.confirmOpen, true);
    await state.executeConfirmed();
    assert.equal(state.confirmOpen, false);
    assert.equal(state.formError, '');
    assert.match(descriptor.template.content, /v-if="canMarkNoShow"/);
});

test('calendar renders no-shows in red while preserving other event colors', async (t) => {
    const app = renderer.createApp(
        { ...exports.default, render: () => null },
        {
            barbershops: [],
            csrfToken: 'test-token',
            canManageAppointments: true,
        },
    );
    app.mount({});
    t.after(() => app.unmount());
    const events = [
        {
            id: 'missed',
            extendedProps: { kind: 'appointment', status: 'no_show' },
        },
        {
            id: 'done',
            extendedProps: { kind: 'appointment', status: 'completed' },
        },
        {
            id: 'scheduled',
            extendedProps: { kind: 'appointment', status: 'scheduled' },
        },
        { id: 'blocked', extendedProps: { kind: 'block' } },
    ];
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: true,
        json: async () => events,
    }));
    let rendered;
    await app._instance.setupState.options.events(
        { startStr: '2026-11-09', endStr: '2026-11-10' },
        (items) => {
            rendered = items;
        },
        (error) => {
            throw error;
        },
    );
    assert.deepEqual(
        rendered.map((event) => event.backgroundColor),
        ['#b91c1c', '#1e3a8a', '#047857', '#52525b'],
    );
    assert.deepEqual(
        rendered.map((event) => event.borderColor),
        rendered.map((event) => event.backgroundColor),
    );
});

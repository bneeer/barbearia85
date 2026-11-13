import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

test('privacy administration is marked for administrators in navigation', async () => {
    const sidebar = await read('components/AppSidebar.vue');
    assert.match(sidebar, /href: '\/clients',[\s\S]*?requiresAdmin: true/);
    assert.match(
        sidebar,
        /href: '\/privacy-requests',[\s\S]*?requiresAdmin: true/,
    );
});

test('deletion preview is explicitly blocked and unlinking is not deletion', async () => {
    const source = await read('pages/PrivacyRequests/Show.vue');
    assert.deepEqual(parse(source).errors, []);
    assert.match(source, /Exclusão definitiva bloqueada/);
    assert.match(source, /Nenhum dado foi excluído/);
    assert.match(source, /value === 'fulfilled'/);
    assert.match(source, /Nenhum dado do cliente será excluído/);
    assert.doesNotMatch(source, /(?:router|unlinkForm)\.delete\(`\/clients\//);
});

test('client history displays status and archived records', async () => {
    const source = await read('pages/Clients/Show.vue');
    assert.deepEqual(parse(source).errors, []);

    for (const status of ['scheduled', 'completed', 'cancelled', 'no_show']) {
        assert.match(source, new RegExp(status));
    }

    assert.match(source, /appointment\.archived/);
    assert.match(source, /PrivacyPagination/);
});

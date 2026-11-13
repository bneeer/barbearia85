import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { baseParse, compile } from '@vue/compiler-dom';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

async function template(path) {
    const { descriptor, errors } = parse(await read(path));
    assert.deepEqual(errors, []);
    assert.ok(descriptor.template);

    return descriptor.template.content;
}

function findFooters(node, conditional = false, inForm = false) {
    const isConditional =
        conditional ||
        (node.props ?? []).some(
            (prop) =>
                prop.type === 7 &&
                ['if', 'else', 'else-if', 'for', 'show'].includes(prop.name),
        );
    const insideForm = inForm || node.tag === 'form';
    const own =
        node.tag === 'PublicFooter'
            ? [{ conditional: isConditional, inForm: insideForm }]
            : [];

    return [
        ...own,
        ...(node.children ?? []).flatMap((child) =>
            findFooters(child, isConditional, insideForm),
        ),
    ];
}

test('public footer links to the privacy policy', async () => {
    const source = await template('resources/js/components/PublicFooter.vue');
    const render = new Function('Vue', compile(source).code)(Vue);
    const app = Vue.createSSRApp({ render });
    const linkName = 'Link';
    app.component(linkName, {
        props: ['href'],
        setup(props, { slots }) {
            return () => Vue.h('a', { href: props.href }, slots.default?.());
        },
    });

    const html = await renderToString(app);
    assert.match(html, /<footer\b/);
    assert.match(html, /href="\/politica-de-privacidade"/);
    assert.match(html, /Política de Privacidade/);
});

test('public pages have one unconditional footer outside forms', async () => {
    for (const path of [
        'resources/js/pages/Booking.vue',
        'resources/js/pages/BookingCancellation.vue',
        'resources/js/pages/PrivacyPolicy.vue',
        'resources/js/layouts/auth/AuthSimpleLayout.vue',
    ]) {
        const nodes = findFooters(baseParse(await template(path)));
        assert.deepEqual(nodes, [{ conditional: false, inForm: false }], path);
    }
});

test('policy renders real unit contacts and identifies missing contacts', async () => {
    const source = await read('resources/js/pages/PrivacyPolicy.vue');
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, {
        id: 'privacy-policy-test',
        inlineTemplate: true,
    })
        .content.replaceAll("'vue'", JSON.stringify(import.meta.resolve('vue')))
        .replaceAll('"vue"', JSON.stringify(import.meta.resolve('vue')))
        .replace(
            "import { Head, Link } from '@inertiajs/vue3';",
            `const Head = () => null;
             const Link = { props: ['href'], setup: (props, { slots }) =>
                 () => _createVNode('a', { href: props.href }, slots.default?.()) };`,
        )
        .replace(
            "import PublicFooter from '@/components/PublicFooter.vue';",
            'const PublicFooter = () => null;',
        );
    const compiled = ts.transpileModule(script, {
        compilerOptions: {
            target: ts.ScriptTarget.ESNext,
            module: ts.ModuleKind.ESNext,
        },
    }).outputText;
    const { default: PrivacyPolicy } = await import(
        `data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`
    );
    const html = await renderToString(
        Vue.createSSRApp(PrivacyPolicy, {
            barbershops: [
                { name: 'Centro', phones: [] },
                { name: 'Norte', phones: ['+5511999999999'] },
            ],
        }),
    );
    assert.match(html, /Centro/);
    assert.match(html, /telefone não informado; canal pendente de definição/);
    assert.match(html, /Norte/);
    assert.match(html, /href="tel:\+5511999999999"/);

    const emptyHtml = await renderToString(
        Vue.createSSRApp(PrivacyPolicy, { barbershops: [] }),
    );
    assert.match(emptyHtml, /Não há contato de unidade disponível/);
});

test('home is excluded and public pages use the intended layouts', async () => {
    const home = await read('resources/js/pages/Welcome.vue');
    assert.doesNotMatch(home, /PublicFooter|politica-de-privacidade/);
    const app = await read('resources/js/app.ts');
    assert.match(app, /case name === 'PrivacyPolicy':\s*return null/);
    assert.match(app, /case name\.startsWith\('auth\/'\):\s*return AuthLayout/);
    const layout = await read('resources/js/layouts/AuthLayout.vue');
    assert.match(layout, /auth\/AuthSimpleLayout\.vue/);
    const simpleLayout = await template(
        'resources/js/layouts/auth/AuthSimpleLayout.vue',
    );
    assert.ok(
        simpleLayout.indexOf('<slot />') <
            simpleLayout.indexOf('<PublicFooter />'),
    );
});

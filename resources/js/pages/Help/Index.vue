<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { Minus, Plus, RotateCcw } from 'lucide-vue-next';
import { marked } from 'marked';
import { computed, onMounted, ref, watch } from 'vue';

const DEFAULT_FONT_SIZE = 16;
const MIN_FONT_SIZE = 12;
const MAX_FONT_SIZE = 24;
const FONT_SIZE_STEP = 2;

type HelpDocument = {
    id: string;
    title: string;
    category: string;
    content: string;
};

const props = defineProps<{ documents: HelpDocument[] }>();
const activeId = ref(props.documents[0]?.id ?? '');
const fontSize = ref(DEFAULT_FONT_SIZE);
const renderedContent = ref('');
const selectedDocument = computed(
    () =>
        props.documents.find((document) => document.id === activeId.value) ??
        props.documents[0] ??
        null,
);
const categories = computed(() => {
    const groups = new Map<string, HelpDocument[]>();

    for (const document of props.documents) {
        const group = groups.get(document.category) ?? [];
        group.push(document);
        groups.set(document.category, group);
    }

    return [...groups.entries()].map(([title, documents]) => ({
        title,
        documents,
    }));
});

function renderDocument() {
    if (!selectedDocument.value || typeof window === 'undefined') {
        renderedContent.value = '';

        return;
    }

    const html = marked.parse(selectedDocument.value.content, {
        async: false,
    });
    renderedContent.value = DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['embed', 'iframe', 'object', 'style'],
    });
}

function changeFontSize(amount: number) {
    fontSize.value = Math.min(
        MAX_FONT_SIZE,
        Math.max(MIN_FONT_SIZE, fontSize.value + amount),
    );
}

watch(selectedDocument, renderDocument);
onMounted(renderDocument);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ajuda', href: '/help' }],
    },
});
</script>

<template>
    <Head title="Ajuda" />
    <main class="space-y-6 p-4 md:p-6">
        <header>
            <h1 class="text-2xl font-semibold">Ajuda</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Manual de instruções da aplicação. Escolha um tópico para
                consultar os passos e orientações.
            </p>
        </header>

        <div
            v-if="documents.length && selectedDocument"
            class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]"
        >
            <nav aria-label="Seções do manual" class="space-y-3">
                <details
                    v-for="category in categories"
                    :key="category.title"
                    class="rounded-lg border p-3"
                    :open="
                        category.documents.some((item) => item.id === activeId)
                    "
                >
                    <summary class="cursor-pointer font-medium">
                        {{ category.title }}
                    </summary>
                    <ul class="mt-2 space-y-1">
                        <li
                            v-for="document in category.documents"
                            :key="document.id"
                        >
                            <button
                                type="button"
                                class="w-full rounded-md px-3 py-2 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                :class="{
                                    'bg-muted font-medium':
                                        document.id === activeId,
                                }"
                                :aria-current="
                                    document.id === activeId
                                        ? 'page'
                                        : undefined
                                "
                                @click="activeId = document.id"
                            >
                                {{ document.title }}
                            </button>
                        </li>
                    </ul>
                </details>
            </nav>

            <article class="min-w-0 rounded-lg border p-4 md:p-6">
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b pb-4"
                >
                    <h2 class="font-medium">{{ selectedDocument.title }}</h2>
                    <div
                        class="flex items-center gap-1"
                        role="group"
                        aria-label="Controles do tamanho do texto"
                    >
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-md border hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Diminuir tamanho da fonte"
                            title="Diminuir tamanho da fonte"
                            :disabled="fontSize <= MIN_FONT_SIZE"
                            @click="changeFontSize(-FONT_SIZE_STEP)"
                        >
                            <Minus aria-hidden="true" class="size-4" />
                        </button>
                        <span
                            class="min-w-16 text-center text-xs text-muted-foreground"
                            aria-live="polite"
                        >
                            {{ fontSize }} px
                        </span>
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-md border hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Aumentar tamanho da fonte"
                            title="Aumentar tamanho da fonte"
                            :disabled="fontSize >= MAX_FONT_SIZE"
                            @click="changeFontSize(FONT_SIZE_STEP)"
                        >
                            <Plus aria-hidden="true" class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center justify-center gap-2 rounded-md border px-3 text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Redefinir tamanho da fonte"
                            title="Redefinir tamanho da fonte"
                            :disabled="fontSize === DEFAULT_FONT_SIZE"
                            @click="fontSize = DEFAULT_FONT_SIZE"
                        >
                            <RotateCcw aria-hidden="true" class="size-4" />
                            <span>Redefinir</span>
                        </button>
                    </div>
                </div>
                <div
                    class="help-markdown max-w-none leading-7 [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_h1]:mt-8 [&_h1]:mb-4 [&_h1]:font-semibold [&_h2]:mt-7 [&_h2]:mb-3 [&_h2]:font-semibold [&_h3]:mt-5 [&_h3]:mb-2 [&_h3]:font-semibold [&_li]:my-1 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_strong]:font-semibold [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6"
                    :style="{ '--help-font-size': `${fontSize}px` }"
                    v-html="renderedContent"
                />
            </article>
        </div>
        <p v-else class="rounded-lg border p-4 text-sm text-muted-foreground">
            Nenhum tópico de ajuda está disponível para este perfil.
        </p>
    </main>
</template>

<style>
.help-markdown {
    font-size: var(--help-font-size);
}

.help-markdown h1 {
    font-size: 1.75em;
}

.help-markdown h2 {
    font-size: 1.5em;
}

.help-markdown h3 {
    font-size: 1.25em;
}
</style>

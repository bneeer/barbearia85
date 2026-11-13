<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PageLink } from '@/types/privacy';

defineProps<{ links: PageLink[] }>();
</script>

<template>
    <nav aria-label="Paginação" class="flex flex-wrap gap-1">
        <template v-for="(link, index) in links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                :aria-current="link.active ? 'page' : undefined"
                :class="[
                    'rounded border px-3 py-1 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted',
                ]"
            >
                {{
                    index === 0
                        ? 'Anterior'
                        : index === links.length - 1
                          ? 'Próxima'
                          : link.label
                }}
            </Link>
            <span
                v-else
                class="rounded border px-3 py-1 text-sm text-muted-foreground"
            >
                {{
                    index === 0
                        ? 'Anterior'
                        : index === links.length - 1
                          ? 'Próxima'
                          : link.label
                }}
            </span>
        </template>
    </nav>
</template>

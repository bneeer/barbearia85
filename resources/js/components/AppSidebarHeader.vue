<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import type { BreadcrumbItem } from '@/types';

const { resolvedAppearance, updateAppearance } = useAppearance();

function toggleAppearance() {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <Button
            type="button"
            variant="ghost"
            size="icon"
            class="ml-auto"
            :aria-label="
                resolvedAppearance === 'dark'
                    ? 'Ativar modo claro'
                    : 'Ativar modo escuro'
            "
            :title="
                resolvedAppearance === 'dark'
                    ? 'Ativar modo claro'
                    : 'Ativar modo escuro'
            "
            :aria-pressed="resolvedAppearance === 'dark'"
            @click="toggleAppearance"
        >
            <Sun
                v-if="resolvedAppearance === 'dark'"
                aria-hidden="true"
                class="size-5"
            />
            <Moon v-else aria-hidden="true" class="size-5" />
        </Button>
    </header>
</template>

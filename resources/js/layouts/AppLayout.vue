<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const page = usePage();

const themeClass = computed(() => {
    return `theme-${page.props.barbershop?.slug || 'default'}`;
});

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div :class="themeClass">
            <slot />
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import { WarningCircle } from '@iconoir/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

type Props = {
    errors: string[];
    title?: string;
};

const props = withDefaults(defineProps<Props>(), {
    title: 'Ocorreu um erro.',
});

const uniqueErrors = computed(() => Array.from(new Set(props.errors)));
</script>

<template>
    <Alert variant="destructive">
        <WarningCircle aria-hidden="true" class="size-4" />
        <AlertTitle>{{ title }}</AlertTitle>
        <AlertDescription>
            <ul class="list-inside list-disc text-sm">
                <li v-for="(error, index) in uniqueErrors" :key="index">
                    {{ error }}
                </li>
            </ul>
        </AlertDescription>
    </Alert>
</template>

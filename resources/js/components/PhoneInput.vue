<script setup lang="ts">
import type { CountryCode } from 'libphonenumber-js';
import {
    getCountries,
    getCountryCallingCode,
    parsePhoneNumberFromString,
} from 'libphonenumber-js/max';
import { computed, nextTick, onMounted, ref, watch, watchEffect } from 'vue';
import { Input } from '@/components/ui/input';
import { formatPhone, internationalPrefix, parsePhone } from '@/lib/phone';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        id: string;
        modelValue?: string | null;
        required?: boolean;
        disabled?: boolean;
    }>(),
    {
        modelValue: '',
        required: false,
        disabled: false,
    },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const country = ref<CountryCode | ''>('BR');
const display = ref('');
const touched = ref(false);
const input = ref<InstanceType<typeof Input> | null>(null);
let lastEmitted: string | undefined;

const regionNames = new Intl.DisplayNames(['pt-BR'], { type: 'region' });
const countries = getCountries()
    .map((code) => ({
        code,
        label: `${regionNames.of(code) ?? code} (+${getCountryCallingCode(code)})`,
    }))
    .sort((a, b) => a.label.localeCompare(b.label, 'pt-BR'));
const parsed = computed(() => parsePhone(display.value, country.value || null));
const error = computed(() =>
    display.value.trim() && !parsed.value
        ? 'Informe um telefone válido para o país selecionado, sem ramal.'
        : '',
);

function syncCountry(value: string) {
    if (internationalPrefix(value).startsWith('+')) {
        const number = parsePhoneNumberFromString(internationalPrefix(value));

        if (number) {
            country.value = number.country ?? '';
        }
    }
}

watch(
    () => props.modelValue,
    (value) => {
        if (value === lastEmitted) {
            return;
        }

        syncCountry(value ?? '');
        display.value = formatPhone(value ?? '', country.value || null);
    },
    { immediate: true },
);

function publish() {
    // Keep incomplete input in the model so an optional invalid phone cannot be silently discarded.
    lastEmitted = parsed.value?.number ?? display.value.trim();
    emit('update:modelValue', lastEmitted);
}

function update(event: Event) {
    const element = event.target;

    if (!(element instanceof HTMLInputElement)) {
        return;
    }

    const raw = element.value;
    const caret = element.selectionStart ?? raw.length;
    const digitsBeforeCaret = raw.slice(0, caret).replace(/\D/g, '').length;
    const deleting =
        event instanceof InputEvent && event.inputType.startsWith('delete');
    syncCountry(raw);
    display.value = deleting ? raw : formatPhone(raw, country.value || null);
    publish();

    if (!deleting) {
        void nextTick(() => {
            element.value = display.value;
            let position = 0;
            let digits = 0;

            while (
                position < display.value.length &&
                digits < digitsBeforeCaret
            ) {
                if (/\d/.test(display.value[position])) {
                    digits++;
                }

                position++;
            }

            if (caret === raw.length) {
                position = display.value.length;
            }

            element.setSelectionRange(position, position);
        });
    }
}

function changeCountry() {
    const number = parsePhoneNumberFromString(
        internationalPrefix(display.value),
        country.value || undefined,
    );
    const national =
        country.value && internationalPrefix(display.value).startsWith('+')
            ? (number?.nationalNumber ?? display.value)
            : display.value;
    display.value = formatPhone(national, country.value || null);
    publish();
}

function blur() {
    touched.value = true;

    if (parsed.value) {
        display.value = formatPhone(display.value, country.value || null);
    }
}

onMounted(() => {
    watchEffect(() => {
        const element = input.value?.$el;

        if (element instanceof HTMLInputElement) {
            element.setCustomValidity(error.value);
        }
    });
});
</script>

<template>
    <div class="min-w-0 space-y-2">
        <div class="flex flex-wrap gap-2">
            <select
                v-model="country"
                :aria-label="`País do telefone ${id}`"
                :disabled="disabled"
                class="h-9 w-40 max-w-full rounded-md border border-input bg-background px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-50"
                @change="changeCountry"
            >
                <option value="">Internacional (+)</option>
                <option
                    v-for="item in countries"
                    :key="item.code"
                    :value="item.code"
                >
                    {{ item.label }}
                </option>
            </select>
            <Input
                :id="id"
                ref="input"
                v-bind="$attrs"
                :model-value="display"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                :required="required"
                :disabled="disabled"
                maxlength="64"
                :placeholder="
                    country ? 'DDD e número' : '+ código do país e número'
                "
                :aria-invalid="touched && !!error"
                :aria-describedby="`${id}-hint${touched && error ? ` ${id}-error` : ''}`"
                class="min-w-40 flex-1"
                @input="update"
                @blur="blur"
            />
        </div>
        <p :id="`${id}-hint`" class="text-xs text-muted-foreground">
            Selecione o país ou cole o número completo com + e código do país.
        </p>
        <p
            v-if="touched && error"
            :id="`${id}-error`"
            class="text-sm text-destructive"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>

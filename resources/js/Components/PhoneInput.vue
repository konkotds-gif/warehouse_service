<script setup>
import { formatPhone } from '../lib/masks';

// Телефон в едином виде +7 (XXX) XXX-XX-XX — вводить можно с 8, 7, +7, пробелами и скобками.
const model = defineModel({ type: String, default: '' });
model.value = formatPhone(model.value);

function onInput(e) {
    const v = formatPhone(e.target.value);
    model.value = v;
    e.target.value = v;
}
</script>

<template>
    <input :value="model" class="input tabular" inputmode="tel" autocomplete="tel" placeholder="+7 (___) ___-__-__" maxlength="18" @input="onInput" />
</template>

<script setup>
import { onMounted, ref } from 'vue';
import Icon from './Icon.vue';

// Поле для ручного сканера / ТСД: сканер «печатает» код и нажимает Enter
const props = defineProps({ placeholder: { type: String, default: 'Отсканируйте QR или введите код' }, autofocus: { type: Boolean, default: true }, disabled: Boolean });
const emit = defineEmits(['scan']);
const value = ref('');
const input = ref(null);

function submit() {
    const v = value.value.trim();
    if (!v) return;
    emit('scan', v);
    value.value = '';
}
function focus() {
    input.value?.focus();
}
defineExpose({ focus });
onMounted(() => props.autofocus && focus());
</script>

<template>
    <form class="relative" @submit.prevent="submit">
        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-blue-600"><Icon name="scan" :size="24" /></span>
        <input ref="input" v-model="value" class="input input-lg !pl-13 tabular" :placeholder="placeholder" :disabled="disabled"
            autocomplete="off" autocapitalize="characters" enterkeyhint="go" />
    </form>
</template>

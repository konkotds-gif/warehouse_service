<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icon from './Icon.vue';

const page = usePage();
const items = ref([]);
let n = 0;

function push(type, text) {
    if (!text) return;
    const id = ++n;
    items.value.push({ id, type, text });
    setTimeout(() => (items.value = items.value.filter((i) => i.id !== id)), type === 'error' ? 8000 : 4500);
}
watch(() => page.props.flash, (f) => {
    push('success', f?.success);
    push('error', f?.error);
}, { immediate: true, deep: true });

defineExpose({ push });
</script>

<template>
    <div class="fixed z-[60] right-4 top-4 left-4 sm:left-auto sm:w-96 flex flex-col gap-2 pointer-events-none">
        <div v-for="t in items" :key="t.id" class="card pointer-events-auto flex gap-3 p-4 shadow-lg">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-white" :class="t.type === 'error' ? 'bg-red-600' : 'bg-green-500'">
                <Icon :name="t.type === 'error' ? 'alert' : 'check'" :size="18" />
            </span>
            <div class="text-sm">
                <div class="text-xs font-semibold text-ink-500">Диски Мира · хранение</div>
                <div class="text-ink-900">{{ t.text }}</div>
            </div>
        </div>
    </div>
</template>

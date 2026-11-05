<script setup>
import { computed, nextTick, ref, watch } from 'vue';

// Селект с текстовым поиском по справочнику брендов шин.
// Если бренда нет в списке — можно оставить свой вариант.
const props = defineProps({ options: { type: Array, default: () => [] }, placeholder: { type: String, default: 'Начните вводить бренд' } });
const model = defineModel({ type: String, default: '' });

const query = ref(model.value);
const open = ref(false);
const active = ref(0);
const list = ref(null);

watch(model, (v) => { if (!open.value) query.value = v; });

const key = (s) => String(s).toLowerCase().replace(/[\s\-.]/g, '');
const filtered = computed(() => {
    const q = key(query.value);
    if (!q || query.value === model.value) return props.options;
    const starts = [], contains = [];
    for (const o of props.options) {
        const k = key(o);
        if (k.startsWith(q)) starts.push(o);
        else if (k.includes(q)) contains.push(o);
    }
    return [...starts, ...contains];
});
const custom = computed(() => {
    const q = query.value.trim();
    return q && !props.options.some((o) => o.toLowerCase() === q.toLowerCase()) ? q : '';
});
const items = computed(() => (custom.value ? [...filtered.value, { custom: custom.value }] : filtered.value));

function show() {
    open.value = true;
    active.value = Math.max(0, filtered.value.indexOf(model.value));
    nextTick(scrollActive);
}
function pick(item) {
    const v = typeof item === 'string' ? item : item.custom;
    model.value = v;
    query.value = v;
    open.value = false;
}
function close() {
    if (!open.value) return;
    open.value = false;
    const q = query.value.trim();
    if (!q) model.value = '';
    else {
        const exact = props.options.find((o) => o.toLowerCase() === q.toLowerCase());
        model.value = exact || q;
    }
    query.value = model.value;
}
function move(d) {
    if (!open.value) return show();
    if (!items.value.length) return;
    active.value = (active.value + d + items.value.length) % items.value.length;
    nextTick(scrollActive);
}
function scrollActive() {
    list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
}
function enter() {
    if (open.value && items.value[active.value] !== undefined) pick(items.value[active.value]);
    else close();
}
function onInput() {
    open.value = true;
    active.value = 0;
}
function clear() {
    model.value = '';
    query.value = '';
}
</script>

<template>
    <div class="relative" @focusout="(e) => !$el.contains(e.relatedTarget) && close()">
        <input v-model="query" class="input pr-16" :placeholder="placeholder" autocomplete="off" role="combobox" :aria-expanded="open"
            @focus="show" @click="show" @input="onInput" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="enter" @keydown.esc="open = false; query = model" @keydown.tab="close" />
        <button v-if="query" type="button" tabindex="-1" class="absolute right-9 top-1/2 -translate-y-1/2 text-ink-500 hover:text-ink-900 px-1" aria-label="Очистить" @mousedown.prevent="clear">×</button>
        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-500 text-xs">▼</span>
        <ul v-if="open" ref="list" role="listbox" class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-[12px] border border-line bg-white py-1 shadow-lg">
            <li v-for="(o, i) in items" :key="typeof o === 'string' ? o : '__custom'" role="option" :aria-selected="o === model" :data-active="i === active"
                class="cursor-pointer px-3 py-2 text-sm" :class="[i === active ? 'bg-blue-50 text-blue-600' : 'hover:bg-bg', o === model ? 'font-semibold' : '']"
                @mousedown.prevent="pick(o)" @mousemove="active = i">
                <template v-if="typeof o === 'string'">{{ o }}</template>
                <template v-else>Нет в списке — оставить «{{ o.custom }}»</template>
            </li>
            <li v-if="!items.length" class="px-3 py-2 text-sm text-ink-500">Ничего не найдено</li>
        </ul>
    </div>
</template>

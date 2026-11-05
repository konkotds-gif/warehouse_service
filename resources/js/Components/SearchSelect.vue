<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { matchScore, norm, optionKeys, queryVariants } from '../lib/search';

// Селект с текстовым поиском по справочнику (бренды шин, марки и модели авто).
// Варианты — строки или { value, aliases }. Поиск понимает неправильную раскладку
// (рус ↔ лат), транслит и алиасы (lib/search.js). Если варианта нет в списке —
// можно оставить свой (allowCustom).
const props = defineProps({
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Начните вводить' },
    allowCustom: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const model = defineModel({ type: String, default: '' });

const query = ref(model.value);
const open = ref(false);
const active = ref(0);
const list = ref(null);

watch(model, (v) => { if (!open.value) query.value = v; });

const opts = computed(() => props.options.map((o) => {
    const value = typeof o === 'string' ? o : o.value;
    return { value, keys: optionKeys(value, typeof o === 'string' ? [] : o.aliases || []) };
}));
const values = computed(() => opts.value.map((o) => o.value));

const filtered = computed(() => {
    const variants = queryVariants(query.value);
    if (!variants.length || query.value === model.value) return values.value;
    const buckets = [[], [], []];
    for (const o of opts.value) {
        const s = matchScore(o.keys, variants);
        if (s !== null) buckets[s].push(o.value);
    }
    return buckets.flat();
});
/** Вариант, который точно соответствует введённому (с учётом раскладки и алиасов). */
function exactMatch(q) {
    const variants = queryVariants(q);
    if (!variants.length) return null;
    return opts.value.find((o) => o.value.toLowerCase() === q.trim().toLowerCase())?.value
        ?? opts.value.find((o) => matchScore(o.keys, variants) === 0)?.value ?? null;
}
const custom = computed(() => {
    const q = query.value.trim();
    // Не предлагаем «оставить как ввели», если введённое точно совпало с вариантом (в т.ч. в другой раскладке)
    return props.allowCustom && q && !values.value.some((v) => norm(v) === norm(q)) && !exactMatch(q) ? q : '';
});
const items = computed(() => (custom.value ? [...filtered.value, { custom: custom.value }] : filtered.value));

function show() {
    if (props.disabled) return;
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
    else model.value = exactMatch(q) || (props.allowCustom ? q : model.value);
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
        <input v-model="query" class="input pr-16" :placeholder="placeholder" :disabled="disabled" autocomplete="off" role="combobox" :aria-expanded="open"
            @focus="show" @click="show" @input="onInput" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="enter" @keydown.esc="open = false; query = model" @keydown.tab="close" />
        <button v-if="query && !disabled" type="button" tabindex="-1" class="absolute right-9 top-1/2 -translate-y-1/2 text-ink-500 hover:text-ink-900 px-1" aria-label="Очистить" @mousedown.prevent="clear">×</button>
        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-500 text-xs">▼</span>
        <ul v-if="open" ref="list" role="listbox" class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-[12px] border border-line bg-white py-1 shadow-lg">
            <li v-for="(o, i) in items" :key="typeof o === 'string' ? o : '__custom'" role="option" :aria-selected="o === model" :data-active="i === active"
                class="cursor-pointer px-3 py-2 text-sm" :class="[i === active ? 'bg-blue-50 text-blue-600' : 'hover:bg-bg', o === model ? 'font-semibold' : '']"
                @mousedown.prevent="pick(o)" @mousemove="active = i">
                <template v-if="typeof o === 'string'">{{ o }}</template>
                <template v-else>Нет в списке — оставить «{{ o.custom }}»</template>
            </li>
            <li v-if="loading" class="px-3 py-2 text-sm text-ink-500">Загрузка…</li>
            <li v-else-if="!items.length" class="px-3 py-2 text-sm text-ink-500">Ничего не найдено</li>
        </ul>
    </div>
</template>

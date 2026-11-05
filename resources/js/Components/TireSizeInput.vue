<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { WIDTHS, PROFILES, DIAMETERS, INDEX_RE, parseTireSize, buildTireSize } from '../lib/tires';

// Размер шины: селекты ширина / профиль / диаметр + необязательный индекс нагрузки и скорости.
// v-model — полная строка '205/55 R16 94H' (как и раньше хранится в tire_size).
const model = defineModel({ type: String, default: '' });

const parts = reactive({ width: '', profile: '', diameter: '', index: '' });
const manual = ref(false);

function load(v) {
    const p = parseTireSize(v);
    if (p) {
        Object.assign(parts, p);
        manual.value = false;
    } else if (v && v.trim()) {
        manual.value = true;
    }
}
load(model.value);

watch(model, (v) => {
    if (!manual.value && v !== buildTireSize(parts)) load(v);
});
watch(parts, () => {
    if (!manual.value) model.value = buildTireSize(parts);
});

const indexBad = computed(() => parts.index && !INDEX_RE.test(parts.index.trim()));

function toggleManual() {
    manual.value = !manual.value;
    if (manual.value) return;
    const p = parseTireSize(model.value);
    if (p) Object.assign(parts, p);
    model.value = buildTireSize(parts);
}
</script>

<template>
    <div>
        <div v-if="!manual" class="grid grid-cols-[1fr_1fr_1fr_5.5rem] items-center gap-2">
            <select v-model="parts.width" class="input tabular" aria-label="Ширина">
                <option value="" disabled>Ширина</option>
                <option v-for="w in WIDTHS" :key="w" :value="w">{{ w }}</option>
            </select>
            <select v-model="parts.profile" class="input tabular" aria-label="Профиль">
                <option value="" disabled>Профиль</option>
                <option v-for="p in PROFILES" :key="p" :value="p">{{ p }}</option>
            </select>
            <select v-model="parts.diameter" class="input tabular" aria-label="Диаметр">
                <option value="" disabled>Диаметр</option>
                <option v-for="d in DIAMETERS" :key="d" :value="d">R{{ d }}</option>
            </select>
            <input v-model="parts.index" class="input tabular uppercase" placeholder="94H" maxlength="9" aria-label="Индекс нагрузки и скорости" title="Индекс нагрузки и скорости (необязательно)" />
        </div>
        <input v-else v-model="model" class="input tabular uppercase" placeholder="31x10.5 R15 109Q" />
        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-500">
            <span v-if="!manual">Ширина / профиль / диаметр · индекс — необязательно</span>
            <span v-else>Ручной ввод, например 31x10.5 R15 109Q</span>
            <button type="button" class="text-blue-600 font-semibold" @click="toggleManual">{{ manual ? 'Выбрать из списков' : 'Другой формат' }}</button>
        </div>
        <div v-if="!manual && indexBad" class="error">Индекс: например 94H или 107/105R</div>
    </div>
</template>

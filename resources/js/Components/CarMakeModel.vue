<script setup>
import { ref, watch } from 'vue';
import SearchSelect from './SearchSelect.vue';
import { getJson } from '../lib/http';

// Марка и модель авто из справочника (загружается из системы записи, см. «Настройки»).
// Два поля без обёртки — встают в сетку родителя. Если справочник пуст или нет связи,
// поля работают как обычный ввод текста.
defineProps({ required: { type: Boolean, default: false } });
const make = defineModel('make', { type: String, default: '' });
const model = defineModel('model', { type: String, default: '' });

const makes = ref(cache.makes || []);
const models = ref([]);
const loadingModels = ref(false);

if (!cache.makes) {
    cache.makesPromise ??= getJson('/w/car-makes').catch(() => { cache.makesPromise = null; return []; });
    cache.makesPromise.then((list) => { if (list.length) cache.makes = list; makes.value = list; });
}

async function loadModels(name) {
    models.value = [];
    if (!name) return;
    if (cache.models[name]) return (models.value = cache.models[name]);
    loadingModels.value = true;
    try {
        const list = await getJson('/w/car-models?make=' + encodeURIComponent(name));
        cache.models[name] = list;
        if (make.value === name) models.value = list;
    } catch {
        /* нет связи — модель вводится текстом */
    } finally {
        loadingModels.value = false;
    }
}
watch(make, (n, o) => {
    if (o && n !== o) model.value = '';
    loadModels(n);
}, { immediate: true });
</script>

<script>
// Справочник общий для всех экземпляров на странице и между переходами.
const cache = { makes: null, makesPromise: null, models: {} };
</script>

<template>
    <div><label class="label">Марка<template v-if="required"> *</template></label>
        <SearchSelect v-model="make" :options="makes" placeholder="Начните вводить марку" /></div>
    <div><label class="label">Модель<template v-if="required"> *</template></label>
        <SearchSelect v-model="model" :options="models" :loading="loadingModels" :placeholder="make ? 'Начните вводить модель' : 'Сначала выберите марку'" /></div>
</template>

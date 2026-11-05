<script setup>
import { computed, watch } from 'vue';
import { formatRuPlate, formatForeignPlate, ruPlateComplete } from '../lib/masks';

// Госномер: РФ (маска А123ВС 86, латиница превращается в кириллицу), иностранный (свободный ввод), без номеров.
const plate = defineModel({ type: String, default: '' });
const mode = defineModel('mode', { type: String, default: 'ru' }); // ru | foreign | none

if (mode.value === 'ru' && plate.value && formatRuPlate(plate.value).replace(' ', '') !== plate.value.replace(/\s/g, '').toUpperCase()) {
    mode.value = 'foreign'; // сохранённый номер не по маске РФ
}
if (mode.value === 'ru') plate.value = formatRuPlate(plate.value);

watch(mode, (m) => {
    if (m === 'none') plate.value = '';
    else if (m === 'ru') plate.value = formatRuPlate(plate.value);
});

function onInput(e) {
    const v = mode.value === 'ru' ? formatRuPlate(e.target.value) : formatForeignPlate(e.target.value);
    plate.value = v;
    e.target.value = v;
}
const bad = computed(() => mode.value === 'ru' && plate.value && !ruPlateComplete(plate.value));
</script>

<template>
    <div>
        <div class="seg w-full grid grid-cols-3 mb-2 text-sm">
            <button type="button" :class="{ on: mode === 'ru' }" @click="mode = 'ru'">РФ</button>
            <button type="button" :class="{ on: mode === 'foreign' }" @click="mode = 'foreign'">Иностранный</button>
            <button type="button" :class="{ on: mode === 'none' }" @click="mode = 'none'">Без номеров</button>
        </div>
        <input v-if="mode !== 'none'" :value="plate" class="input tabular uppercase" autocomplete="off"
            :placeholder="mode === 'ru' ? 'А123ВС 86' : 'Номер как на машине'" :maxlength="mode === 'ru' ? 10 : 16" @input="onInput" />
        <div v-else class="input flex items-center text-ink-500">Новый автомобиль, номера ещё не получены</div>
        <div v-if="bad" class="mt-1 text-xs text-ink-500">Формат: буква, 3 цифры, 2 буквы, регион (А123ВС 86). Буквы: А В Е К М Н О Р С Т У Х</div>
    </div>
</template>

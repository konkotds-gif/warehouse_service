<script setup>
import { router } from '@inertiajs/vue3';

// Выбор локации для руководителя/администратора (фильтр страницы)
const props = defineProps({ locations: Array, current: Object, param: { type: String, default: 'location' } });
function change(e) {
    const url = new URL(window.location.href);
    if (e.target.value) url.searchParams.set(props.param, e.target.value);
    else url.searchParams.delete(props.param);
    router.visit(url.pathname + url.search, { preserveScroll: true });
}
</script>

<template>
    <select class="input !w-auto" :value="current?.id || ''" @change="change">
        <option value="">Все локации</option>
        <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.label }}</option>
    </select>
</template>

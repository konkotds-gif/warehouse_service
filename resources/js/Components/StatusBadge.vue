<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({ status: String, label: String });
const meta = computed(() => usePage().props.dict?.statuses?.[props.status] || { label: props.label || props.status, color: 'ink' });
</script>

<template>
    <span class="badge" :class="`badge-${meta.color}`">{{ label || meta.label }}</span>
</template>

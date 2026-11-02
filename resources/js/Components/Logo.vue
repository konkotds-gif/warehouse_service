<script setup>
defineProps({ light: Boolean, small: Boolean, tag: { type: Boolean, default: true } });
</script>

<template>
    <div class="flex items-baseline gap-1 select-none" :class="small ? 'text-lg' : 'text-2xl'">
        <span class="font-black text-red-600">Диски</span>
        <span class="font-black" :class="light ? 'text-white' : 'text-blue-600'">МИРА</span>
        <span v-if="!small && tag" class="ml-2 text-xs font-semibold uppercase tracking-widest" :class="light ? 'text-white/60' : 'text-ink-500'">хранение</span>
    </div>
</template>

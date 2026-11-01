<script setup>
defineProps({ eyebrow: String, title: String, subtitle: String });
</script>

<template>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div v-if="eyebrow" class="eyebrow mb-1">{{ eyebrow }}</div>
            <h1 class="text-2xl sm:text-3xl">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-ink-500">{{ subtitle }}</p>
        </div>
        <div class="flex flex-wrap gap-2"><slot /></div>
    </div>
</template>

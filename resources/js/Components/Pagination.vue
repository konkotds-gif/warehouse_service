<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ links: Array });
</script>

<template>
    <nav v-if="links && links.length > 3" class="flex flex-wrap gap-1 mt-4">
        <template v-for="(l, i) in links" :key="i">
            <Link v-if="l.url" :href="l.url" preserve-scroll class="min-w-10 h-10 px-3 inline-flex items-center justify-center rounded-[10px] text-sm border"
                :class="l.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-line hover:border-blue-600'" v-html="l.label" />
            <span v-else class="min-w-10 h-10 px-3 inline-flex items-center justify-center text-sm text-ink-500" v-html="l.label" />
        </template>
    </nav>
</template>

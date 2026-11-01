<script setup>
import { net, flush } from '../lib/http';
</script>

<template>
    <button type="button" class="chip" :class="net.online ? 'bg-green-500/15 text-green-700' : 'bg-red-50 text-red-600'" @click="flush"
        :title="net.queue.length ? 'Отправить очередь сейчас' : ''">
        <span class="h-2 w-2 rounded-full" :class="net.online ? 'bg-green-500' : 'bg-red-600'"></span>
        <span v-if="net.online && !net.queue.length">В сети</span>
        <span v-else-if="net.online">Отправка: {{ net.queue.length }}</span>
        <span v-else>Офлайн<template v-if="net.queue.length">, {{ net.queue.length }} в очереди</template></span>
    </button>
</template>

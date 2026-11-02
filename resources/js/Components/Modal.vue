<script setup>
import Icon from './Icon.vue';
defineProps({ show: Boolean, title: String, wide: Boolean });
defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-navy-900/50 p-0 sm:p-4" @click.self="$emit('close')">
            <div class="card w-full max-h-[92vh] overflow-auto rounded-b-none sm:rounded-b-[16px]" :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'">
                <div class="flex items-center justify-between px-5 pt-4 pb-2">
                    <h3 class="text-lg">{{ title }}</h3>
                    <button class="btn-ghost !min-h-9 !px-2" @click="$emit('close')" aria-label="Закрыть"><Icon name="x" /></button>
                </div>
                <div class="px-5 pb-5"><slot /></div>
            </div>
        </div>
    </Teleport>
</template>

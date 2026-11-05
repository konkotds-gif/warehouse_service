<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import { dialog, answer } from '../lib/confirm';

const okBtn = ref(null);
watch(() => dialog.open, async (open) => {
    if (open) {
        await nextTick();
        okBtn.value?.focus();
    }
});
function onKey(e) {
    if (!dialog.open) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        answer(false);
    }
}
let off;
onMounted(() => {
    window.addEventListener('keydown', onKey);
    off = router.on('navigate', () => dialog.open && answer(false));
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    off?.();
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-100">
            <div v-if="dialog.open" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center bg-navy-900/50 p-0 sm:p-4" role="dialog" aria-modal="true" @click.self="answer(false)">
                <div class="card w-full sm:max-w-md rounded-b-none sm:rounded-b-[16px] p-5">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-10 w-10 flex-none items-center justify-center rounded-full" :class="dialog.danger ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600'">
                            <Icon :name="dialog.danger ? 'alert' : 'check'" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-lg">{{ dialog.title }}</h3>
                            <p class="mt-1 whitespace-pre-line text-ink-900">{{ dialog.text }}</p>
                        </div>
                    </div>
                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button class="btn-secondary" @click="answer(false)">{{ dialog.cancel }}</button>
                        <button ref="okBtn" :class="dialog.danger ? 'btn-primary' : 'btn-blue'" @click="answer(true)">{{ dialog.ok }}</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue';
import { getJson } from '../lib/http';
import { phone } from '../lib/format';

// Поиск клиента по ФИО, телефону или госномеру. v-model — выбранный клиент ({ id, full_name, phone }).
const props = defineProps({ excludeId: { type: String, default: null } });
const picked = defineModel({ type: Object, default: null });
const q = ref('');
const found = ref([]);
const searching = ref(false);
let timer;
watch(q, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) return (found.value = []);
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            const list = await getJson('/w/clients?q=' + encodeURIComponent(v.trim()));
            found.value = list.filter((c) => c.id !== props.excludeId);
        } catch {
            found.value = [];
        } finally {
            searching.value = false;
        }
    }, 300);
});
</script>

<template>
    <div v-if="picked" class="flex items-center justify-between gap-2 rounded-[12px] border border-line p-3">
        <div><div class="font-semibold">{{ picked.full_name }}</div><div class="text-sm text-ink-500 tabular">{{ phone(picked.phone) }}</div></div>
        <button type="button" class="btn-ghost !min-h-8 !text-xs" @click="picked = null">Другой</button>
    </div>
    <div v-else>
        <input v-model="q" class="input" placeholder="ФИО, телефон или госномер клиента" />
        <div v-if="searching" class="mt-2 text-sm text-ink-500">Ищу…</div>
        <div v-else-if="q.trim().length >= 2 && !found.length" class="mt-2 text-sm text-ink-500">Не найдено. Нового клиента заведите в разделе «Клиенты».</div>
        <div class="mt-2 grid gap-1 max-h-64 overflow-y-auto">
            <button v-for="c in found" :key="c.id" type="button" class="text-left rounded-[10px] px-3 py-2 hover:bg-blue-50" @click="picked = c">
                <span class="font-semibold">{{ c.full_name }}</span>
                <span class="text-sm text-ink-500 tabular"> · {{ phone(c.phone) }}</span>
                <span v-if="c.vehicles?.length" class="block text-xs text-ink-500">{{ c.vehicles.map((v) => v.title).join(', ') }}</span>
            </button>
        </div>
    </div>
</template>

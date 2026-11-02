<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import ScanInput from '../../Components/ScanInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { postJson, beep } from '../../lib/http';
import { dt, can } from '../../lib/format';

const props = defineProps({ transfer: Object });
const t = computed(() => props.transfer);
const mode = computed(() => (t.value.status === 'created' ? 'out' : ['shipped', 'discrepancy'].includes(t.value.status) ? 'in' : null));
const qty = ref(null);
const msg = ref(null);
const flash = ref('');
const done = computed(() => t.value.lines.filter((l) => (mode.value === 'out' ? l.scanned_out : l.scanned_in)).length);

async function scan(code) {
    const url = `/w/transfers/${t.value.id}/${mode.value === 'out' ? 'scan-out' : 'scan-in'}`;
    try {
        const res = await postJson(url, { code, qty: qty.value }, { queue: true, label: `${t.value.number}: ${code}` });
        msg.value = { ok: true, text: res.message };
        flash.value = 'flash-ok';
        beep(true);
        qty.value = null;
        if (!res.queued) router.reload({ only: ['transfer'] });
    } catch (e) {
        msg.value = { ok: false, text: e.message };
        flash.value = 'flash-err';
        beep(false);
    }
    setTimeout(() => (flash.value = ''), 700);
}
const badge = { created: 'badge-ink', shipped: 'badge-blue', received: 'badge-green', discrepancy: 'badge-red', cancelled: 'badge-gray' };
</script>

<template>
    <Head :title="`Перемещение ${transfer.number}`" />
    <div class="mb-6">
        <div class="eyebrow mb-1">Перемещение</div>
        <h1 class="text-3xl tabular">{{ t.number }} <span class="badge align-middle" :class="badge[t.status]">{{ t.status_label }}</span></h1>
        <p class="text-ink-500 mt-1">{{ t.from.label }} → {{ t.to.label }}<template v-if="t.driver"> · {{ t.driver }}</template></p>
        <p class="text-sm text-ink-500">Создано {{ dt(t.created_at) }} · отгружено {{ dt(t.shipped_at) }} · принято {{ dt(t.received_at) }}</p>
    </div>

    <div v-if="mode" class="card p-5 mb-4" :class="flash">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="text-lg">{{ mode === 'out' ? 'Погрузка: сканируйте бирки' : 'Приёмка: сканируйте бирки' }}</h2>
            <span class="chip bg-blue-50 text-blue-600 tabular">{{ done }} / {{ t.lines.length }}</span>
        </div>
        <ScanInput @scan="scan" />
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-ink-500">Колёс фактически (если не совпадает с договором):</span>
            <button v-for="n in 8" :key="n" class="h-9 w-9 rounded-[10px] border font-bold" :class="qty === n ? 'bg-amber-500 text-white border-amber-500' : 'border-line'" @click="qty = qty === n ? null : n">{{ n }}</button>
        </div>
        <div v-if="msg" class="mt-3 flex gap-2 font-semibold" :class="msg.ok ? 'text-green-700' : 'text-red-600'"><Icon :name="msg.ok ? 'check' : 'alert'" /> {{ msg.text }}</div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="mode === 'out' && can('ship-transfer')" class="btn-primary" :disabled="!done" @click="router.post(`/transfers/${t.id}/ship`)">Отгрузить ({{ done }}) →</button>
            <button v-if="mode === 'out'" class="btn-ghost" @click="router.post(`/transfers/${t.id}/cancel`)">Отменить перемещение</button>
            <button v-if="t.status === 'shipped' && can('receive-transfer')" class="btn-primary" @click="router.post(`/transfers/${t.id}/complete`)">Завершить приёмку</button>
        </div>
        <p v-if="mode === 'out'" class="mt-2 text-xs text-ink-500">Неотсканированные комплекты при отгрузке останутся на месте и будут исключены из перемещения.</p>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>QR</th><th>Комплект</th><th>Госномер</th><th>Статус</th><th>Адрес</th><th>Погрузка</th><th>Приёмка</th></tr></thead>
            <tbody>
                <tr v-for="l in t.lines" :key="l.id">
                    <td class="font-bold tabular"><a :href="`/contracts/${l.set.contract_id}`" class="text-blue-600">{{ l.set.qr_code }}</a></td>
                    <td>{{ l.set.title }}</td>
                    <td class="tabular">{{ l.set.plate }}</td>
                    <td><StatusBadge :status="l.set.status" /></td>
                    <td class="tabular">{{ l.set.address }}</td>
                    <td><span v-if="l.scanned_out" class="text-green-700 font-semibold">✓ {{ l.qty_out }} шт</span><span v-else class="text-ink-500">—</span></td>
                    <td><span v-if="l.scanned_in" class="text-green-700 font-semibold">✓ {{ l.qty_in }} шт</span><span v-else class="text-ink-500">—</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

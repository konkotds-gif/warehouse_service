<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import ScanInput from '../../Components/ScanInput.vue';
import { postJson, beep } from '../../lib/http';

const props = defineProps({ inventory: Object, report: Object, scans: Array });
const cell = ref(null);
const msg = ref(null);

async function scan(v) {
    if (/^\d{2}_\d{2}_\d{2}$/.test(v)) {
        cell.value = v;
        msg.value = { ok: true, text: `Ячейка ${v}: сканируйте бирки или нажмите «Пусто»` };
        return beep(true);
    }
    if (!cell.value) {
        msg.value = { ok: false, text: 'Сначала отсканируйте ячейку' };
        return beep(false);
    }
    await send(v);
}
async function send(code) {
    try {
        const res = await postJson(`/w/inventories/${props.inventory.id}/scan`, { address: cell.value, code }, { queue: true, label: `Инв. ${cell.value}` });
        msg.value = { ok: true, text: res.message };
        beep(true);
        router.reload({ only: ['report', 'scans'] });
    } catch (e) {
        msg.value = { ok: false, text: e.message };
        beep(false);
    }
}
const finish = (apply) => router.post(`/inventories/${props.inventory.id}/finish`, { apply });
</script>

<template>
    <Head :title="`Инвентаризация ${inventory.id}`" />
    <div class="eyebrow mb-1">Инвентаризация № {{ inventory.id }}</div>
    <h1 class="text-2xl mb-4">{{ inventory.location }}<template v-if="inventory.row"> · ряд {{ inventory.row }}</template></h1>

    <div v-if="inventory.status === 'open'" class="card p-5 mb-4">
        <div class="mb-2 text-sm">Текущая ячейка: <b class="tabular text-lg">{{ cell || '—' }}</b></div>
        <ScanInput placeholder="QR ячейки, затем QR бирок в ней" @scan="scan" />
        <div class="mt-3 flex gap-2">
            <button class="btn-secondary" :disabled="!cell" @click="send(null)">Ячейка пустая</button>
        </div>
        <div v-if="msg" class="mt-3 flex gap-2 font-semibold" :class="msg.ok ? 'text-green-700' : 'text-red-600'"><Icon :name="msg.ok ? 'check' : 'alert'" /> {{ msg.text }}</div>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="card p-4"><div class="text-ink-500 text-sm">Ячеек проверено</div><div class="text-2xl font-black tabular">{{ report.cells_scanned }} / {{ report.cells_total }}</div></div>
        <div class="card p-4"><div class="text-ink-500 text-sm">Недостаёт</div><div class="text-2xl font-black text-red-600">{{ report.missing.length }}</div></div>
        <div class="card p-4"><div class="text-ink-500 text-sm">Не на своём месте</div><div class="text-2xl font-black text-amber-700">{{ report.misplaced.length }}</div></div>
        <div class="card p-4"><div class="text-ink-500 text-sm">Лишние</div><div class="text-2xl font-black text-blue-600">{{ report.extra.length }}</div></div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="card p-4"><h3 class="mb-2">Недостающие</h3><div v-for="m in report.missing" :key="m.qr_code" class="text-sm tabular">{{ m.qr_code }} — ждали в {{ m.expected_address }}</div></div>
        <div class="card p-4"><h3 class="mb-2">Не на своём месте</h3><div v-for="m in report.misplaced" :key="m.qr_code" class="text-sm tabular">{{ m.qr_code }}: {{ m.expected_address }} → найден в {{ m.found_address }}</div></div>
        <div class="card p-4"><h3 class="mb-2">Лишние</h3><div v-for="m in report.extra" :key="m.qr_code" class="text-sm tabular">{{ m.qr_code }} в {{ m.found_address }} {{ m.known ? `(статус ${m.status})` : '(неизвестная бирка)' }}</div></div>
    </div>
    <div v-if="report.unscanned_cells?.length" class="mt-4 card p-4 text-sm"><b>Не проверены ячейки:</b> <span class="tabular">{{ report.unscanned_cells.join(', ') }}</span></div>

    <div v-if="inventory.status === 'open'" class="mt-6 flex flex-wrap gap-2">
        <button class="btn-primary" @click="finish(true)">Завершить и исправить адреса</button>
        <button class="btn-secondary" @click="finish(false)">Завершить без исправлений</button>
    </div>
    <p v-if="inventory.status === 'open'" class="mt-2 text-xs text-ink-500">«Исправить»: найденные не на месте получат фактический адрес, недостающие — статус «Утерян / расхождение».</p>
</template>

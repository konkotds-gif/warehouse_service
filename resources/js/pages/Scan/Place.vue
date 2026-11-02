<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import ScanInput from '../../Components/ScanInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { getJson, postJson, beep, net, clearErrors } from '../../lib/http';

// Размещение (план, 6.3): скан QR бирки → система предлагает ячейку → скан QR ячейки
const stage = ref('set'); // set | cell
const code = ref('');
const info = ref(null);
const message = ref(null);
const flash = ref('');
const busy = ref(false);
const input = ref(null);
const history = ref([]);

function show(ok, text) {
    message.value = { ok, text };
    flash.value = ok ? 'flash-ok' : 'flash-err';
    beep(ok);
    setTimeout(() => (flash.value = ''), 700);
}

async function onScan(v) {
    if (stage.value === 'set') {
        if (/^\d{2}_\d{2}_\d{2}$/.test(v)) return show(false, 'Сначала отсканируйте бирку колеса, затем ячейку');
        code.value = v;
        info.value = null;
        try {
            const res = await getJson('/w/scan?code=' + encodeURIComponent(v));
            const set = res.sets.find((s) => s.qr_code === v) || (res.sets.length === 1 ? res.sets[0] : null);
            if (!set) return show(false, 'По договору несколько комплектов — отсканируйте бирку с суффиксом /2');
            if (!['received', 'placed', 'ordered', 'lost'].includes(set.status)) return show(false, `Комплект ${set.qr_code}: «${set.status_label}» — размещать нельзя`);
            info.value = { ...res, set };
            message.value = null;
        } catch (e) {
            if (!net.online) {
                info.value = { set: { qr_code: v }, offline: true };
            } else {
                return show(false, e.message);
            }
        }
        stage.value = 'cell';
        beep(true);
    } else {
        if (!/^\d{2}_\d{2}_\d{2}$/.test(v)) return show(false, `«${v}» — не адрес ячейки (формат XX_YY_ZZ)`);
        busy.value = true;
        try {
            const res = await postJson('/w/place', { code: code.value, address: v, suggested: info.value?.set?.suggested || null }, { queue: true, label: `${code.value} → ${v}` });
            show(true, res.message);
            history.value.unshift({ code: code.value, address: v, queued: !!res.queued });
            reset();
        } catch (e) {
            show(false, e.message);
        } finally {
            busy.value = false;
        }
    }
}
function reset() {
    stage.value = 'set';
    code.value = '';
    info.value = null;
    input.value?.focus();
}
</script>

<template>
    <Head title="Размещение" />
    <div class="max-w-xl mx-auto rounded-[16px]" :class="flash">
        <div class="eyebrow mb-1">Склад · ТСД</div>
        <h1 class="text-2xl mb-4">Размещение</h1>

        <div class="grid grid-cols-2 gap-2 mb-4">
            <div class="rounded-[12px] p-3 text-center font-semibold" :class="stage === 'set' ? 'bg-blue-600 text-white' : 'bg-green-500/15 text-green-700'">1. Бирка колеса</div>
            <div class="rounded-[12px] p-3 text-center font-semibold" :class="stage === 'cell' ? 'bg-blue-600 text-white' : 'bg-white border border-line text-ink-500'">2. Ячейка</div>
        </div>

        <ScanInput ref="input" :disabled="busy" :placeholder="stage === 'set' ? 'Отсканируйте QR на бирке' : 'Отсканируйте QR ячейки XX_YY_ZZ'" @scan="onScan" />

        <div v-if="message" class="mt-4 card p-4 flex gap-3 items-start" :class="message.ok ? 'border-green-500' : 'border-red-600'">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-white" :class="message.ok ? 'bg-green-500' : 'bg-red-600'"><Icon :name="message.ok ? 'check' : 'alert'" :size="18" /></span>
            <div class="font-semibold">{{ message.text }}</div>
        </div>

        <div v-if="stage === 'cell' && info" class="mt-4 card p-5">
            <div class="flex items-center gap-3">
                <span class="text-2xl font-black tabular">{{ info.set.qr_code }}</span>
                <StatusBadge v-if="info.set.status" :status="info.set.status" />
            </div>
            <div v-if="info.offline" class="mt-2 text-sm text-amber-700">Нет связи — скан будет отправлен, когда появится сеть.</div>
            <template v-else>
                <div class="mt-1 text-ink-500">{{ info.contract.vehicle }} · {{ info.set.title }}</div>
                <div class="mt-1 text-sm">Сейчас: <b class="tabular">{{ info.set.address }}</b></div>
                <div v-if="info.set.suggested" class="mt-4 rounded-[16px] bg-blue-50 p-4 text-center">
                    <div class="text-sm font-semibold text-blue-600 uppercase tracking-wider">Положите в ячейку</div>
                    <div class="text-5xl font-black tabular text-ink-900 mt-1">{{ info.set.suggested }}</div>
                </div>
                <div v-else class="mt-4 rounded-[12px] bg-red-50 p-3 text-sm text-red-600">Свободных ячеек на складе нет — выберите вручную.</div>
            </template>
            <button class="btn-ghost mt-3" @click="reset">Отменить</button>
        </div>

        <div v-if="net.errors.length" class="mt-4 card border-red-600 p-4">
            <div class="font-semibold text-red-600">Не прошли сканы из офлайн-очереди</div>
            <div v-for="e in net.errors" :key="e.id" class="text-sm mt-1">{{ e.label }}: {{ e.error }}</div>
            <button class="btn-ghost mt-2" @click="clearErrors">Понятно</button>
        </div>

        <div v-if="history.length" class="mt-6">
            <div class="text-sm font-semibold text-ink-500 mb-2">Размещено за смену</div>
            <div v-for="(h, i) in history" :key="i" class="flex justify-between py-2 border-b border-line text-sm tabular">
                <span>{{ h.code }}</span><span><b>{{ h.address }}</b> <span v-if="h.queued" class="badge badge-amber">в очереди</span></span>
            </div>
        </div>
        <Link href="/tasks?type=place" class="btn-link mt-6">Задания на размещение →</Link>
    </div>
</template>

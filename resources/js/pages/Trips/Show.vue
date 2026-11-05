<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import ScanInput from '../../Components/ScanInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { postJson, beep } from '../../lib/http';
import { dt, can } from '../../lib/format';
import { ask } from '../../lib/confirm';

const props = defineProps({ trip: Object, pool: Array });
const t = computed(() => props.trip);
const mode = computed(() => (t.value.status === 'loading' && can('ship-transfer') ? 'out' : t.value.status === 'shipped' && can('receive-transfer') ? 'in' : null));
const lines = computed(() => t.value.transfers.flatMap((tr) => tr.lines.map((l) => ({ ...l, transfer: tr }))));
// Сверка считается по перемещениям, которые ещё в работе (в пути / ожидают погрузки)
const active = computed(() => lines.value.filter((l) => (mode.value === 'in' ? ['shipped', 'discrepancy'].includes(l.transfer.status) : true)));
const done = computed(() => active.value.filter((l) => (mode.value === 'in' ? l.scanned_in : l.scanned_out)).length);
const missing = computed(() => active.value.length - done.value);
// Колёса: бирки на колёсах комплекта одинаковые, каждый скан — одно колесо
const wheelsOf = (l) => (mode.value === 'in' ? (l.scanned_in ? l.qty_in : l.wheels_in) : (l.scanned_out ? l.qty_out : l.wheels_out));
const expectedOf = (l) => (mode.value === 'in' ? l.qty_out ?? l.set.qty : l.set.qty);
const wheelsDone = computed(() => active.value.reduce((n, l) => n + wheelsOf(l), 0));
const wheelsTotal = computed(() => active.value.reduce((n, l) => n + expectedOf(l), 0));
const partial = computed(() => active.value.filter((l) => !(mode.value === 'in' ? l.scanned_in : l.scanned_out) && wheelsOf(l) > 0));
const qty = ref(null);
const msg = ref(null);
const flash = ref('');
const info = useForm({ driver: props.trip.driver || '', vehicle: props.trip.vehicle || '' });
const add = useForm({ transfer_ids: [] });
const tripBadge = { loading: 'badge-ink', shipped: 'badge-blue', done: 'badge-green', cancelled: 'badge-gray' };
const trBadge = { created: 'badge-ink', shipped: 'badge-blue', received: 'badge-green', discrepancy: 'badge-red', cancelled: 'badge-gray' };

async function scan(code) {
    const url = `/w/trips/${t.value.id}/${mode.value === 'out' ? 'scan-out' : 'scan-in'}`;
    try {
        const res = await postJson(url, { code, qty: qty.value }, { queue: true, label: `${t.value.number}: ${code}` });
        msg.value = { ok: true, text: res.message };
        flash.value = 'flash-ok';
        beep(true);
        qty.value = null;
        if (!res.queued) router.reload({ only: ['trip'] });
    } catch (e) {
        msg.value = { ok: false, text: e.message };
        flash.value = 'flash-err';
        beep(false);
    }
    setTimeout(() => (flash.value = ''), 700);
}
async function ship() {
    const parts = [];
    if (partial.value.length) parts.push(`Погружены не полностью: ${partial.value.map((l) => `${l.set.qr_code} (${l.wheels_out} из ${l.set.qty})`).join(', ')} — уйдут с фактическим количеством, по ним будет создано задание «расхождение».`);
    const none = missing.value - partial.value.length;
    if (none) parts.push(`Не погружено комплектов: ${none}. Они останутся на месте и вернутся в пул перевозок.`);
    const text = parts.length ? parts.join('\n\n') : 'Все колёса погружены.';
    if (await ask(text, { title: `Отгрузка ведомости ${t.value.number}`, ok: 'Отгрузить' })) router.post(`/trips/${t.value.id}/ship`);
}
async function cancel() {
    if (await ask('Перемещения вернутся в пул перевозок, отметки погрузки сбросятся.', { title: `Отменить ведомость ${t.value.number}?`, ok: 'Отменить ведомость', cancel: 'Не отменять', danger: true })) router.post(`/trips/${t.value.id}/cancel`);
}
async function detach(tr) {
    if (await ask('Перемещение вернётся в пул перевозок, отметки погрузки по нему сбросятся.', { title: `Убрать ${tr.number} из ведомости?`, ok: 'Убрать', danger: true })) router.delete(`/trips/${t.value.id}/transfers/${tr.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Ведомость ${trip.number}`" />
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="eyebrow mb-1"><Link href="/tasks/transport">Перевозки</Link> · ведомость на перевозку</div>
            <h1 class="text-3xl tabular">{{ t.number }} <span class="badge align-middle" :class="tripBadge[t.status]">{{ t.status_label }}</span></h1>
            <p class="text-ink-500 mt-1">{{ t.route.join(', ') }} · перемещений: {{ t.transfers.length }}</p>
            <p class="text-sm text-ink-500">Создана {{ dt(t.created_at) }}<template v-if="t.author"> ({{ t.author }})</template> · отгружена {{ dt(t.shipped_at) }} · доставлена {{ dt(t.completed_at) }}</p>
        </div>
        <a :href="`/trips/${t.id}/print`" target="_blank" class="btn-secondary"><Icon name="printer" /> Печать ведомости</a>
    </div>

    <div class="card p-5 mb-4 grid gap-4 sm:grid-cols-3">
        <div><label class="label">Водитель</label><input v-model="info.driver" class="input" :disabled="t.status !== 'loading'" /></div>
        <div><label class="label">Автомобиль</label><input v-model="info.vehicle" class="input" :disabled="t.status !== 'loading'" /></div>
        <div v-if="t.status === 'loading'" class="flex items-end"><button class="btn-secondary w-full" :disabled="info.processing || !info.isDirty" @click="info.put(`/trips/${t.id}`, { preserveScroll: true })">Сохранить</button></div>
    </div>

    <div v-if="mode" class="card p-5 mb-4" :class="flash">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="text-lg">{{ mode === 'out' ? 'Погрузка: сканируйте бирки' : 'Приёмка: сканируйте бирки' }}</h2>
            <div class="flex gap-2">
                <span class="chip bg-blue-50 text-blue-600 tabular">колёс {{ wheelsDone }} / {{ wheelsTotal }}</span>
                <span class="chip bg-blue-50 text-blue-600 tabular">комплектов {{ done }} / {{ active.length }}</span>
                <span v-if="wheelsTotal - wheelsDone > 0" class="chip bg-amber-50 text-amber-700 tabular">не хватает {{ wheelsTotal - wheelsDone }} кол.</span>
            </div>
        </div>
        <ScanInput @scan="scan" />
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-ink-500">Сканируйте бирку на каждом колесе. Если колёс фактически не столько, сколько по договору, нажмите число и отсканируйте бирку один раз:</span>
            <button v-for="n in 8" :key="n" class="h-9 w-9 rounded-[10px] border font-bold" :class="qty === n ? 'bg-amber-500 text-white border-amber-500' : 'border-line'" @click="qty = qty === n ? null : n">{{ n }}</button>
        </div>
        <div v-if="msg" class="mt-3 flex gap-2 font-semibold" :class="msg.ok ? 'text-green-700' : 'text-red-600'"><Icon :name="msg.ok ? 'check' : 'alert'" /> {{ msg.text }}</div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="mode === 'out'" class="btn-primary" :disabled="!done" @click="ship">Отгрузить ({{ done }} из {{ active.length }}) →</button>
            <button v-if="mode === 'in'" class="btn-primary" @click="router.post(`/trips/${t.id}/complete`)">Завершить приёмку</button>
        </div>
        <p v-if="mode === 'out'" class="mt-2 text-xs text-ink-500">Лишний скан (то же колесо дважды) не засчитывается. Сверка идёт по всей ведомости. Неотсканированные комплекты при отгрузке останутся на месте и вернутся в пул перевозок отдельным перемещением.</p>
    </div>

    <div v-for="tr in t.transfers" :key="tr.id" class="card overflow-x-auto mb-4">
        <div class="flex flex-wrap items-center gap-2 p-4">
            <Link :href="`/transfers/${tr.id}`" class="font-bold text-blue-600 tabular text-lg">{{ tr.number }}</Link>
            <span class="badge" :class="trBadge[tr.status]">{{ tr.status_label }}</span>
            <span>{{ tr.from.label }} → {{ tr.to.label }}</span>
            <span class="text-ink-500 text-sm">· договоров {{ tr.contracts_count }}, комплектов {{ tr.lines.length }}, колёс {{ tr.wheels }}</span>
            <button v-if="t.status === 'loading' && can('transfers')" class="btn-ghost !min-h-9 ml-auto" @click="detach(tr)">Убрать из ведомости</button>
        </div>
        <table class="table">
            <thead><tr><th>QR</th><th>Клиент</th><th>Комплект</th><th>Госномер</th><th>Статус</th><th>Адрес</th><th>Погрузка</th><th>Приёмка</th></tr></thead>
            <tbody>
                <tr v-for="l in tr.lines" :key="l.id" :class="(t.status === 'loading' && !l.scanned_out) || (t.status === 'shipped' && !l.scanned_in && tr.status !== 'received') ? 'bg-amber-50/40' : ''">
                    <td class="font-bold tabular"><a :href="`/contracts/${l.set.contract_id}`" class="text-blue-600">{{ l.set.qr_code }}</a></td>
                    <td>{{ l.client }}</td>
                    <td>{{ l.set.title }}</td>
                    <td class="tabular">{{ l.set.plate }}</td>
                    <td><StatusBadge :status="l.set.status" /></td>
                    <td class="tabular">{{ l.set.address }}</td>
                    <td>
                        <span v-if="l.scanned_out" class="text-green-700 font-semibold">✓ {{ l.qty_out }} шт</span>
                        <span v-else-if="l.wheels_out" class="text-amber-700 font-semibold tabular">{{ l.wheels_out }} / {{ l.set.qty }}</span>
                        <span v-else class="text-ink-500">0 / {{ l.set.qty }}</span>
                        <template v-if="l.wheels_out && t.status === 'loading' && can('ship-transfer')">
                            <button class="ml-2 text-xs text-ink-500 underline" title="Отменить скан одного колеса" @click="router.post(`/trips/${t.id}/lines/${l.id}/unscan`, { all: false }, { preserveScroll: true })">−1</button>
                            <button class="ml-2 text-xs text-ink-500 underline" @click="router.post(`/trips/${t.id}/lines/${l.id}/unscan`, { all: true }, { preserveScroll: true })">сбросить</button>
                        </template>
                    </td>
                    <td><span v-if="l.scanned_in" class="text-green-700 font-semibold">✓ {{ l.qty_in }} шт</span>
                        <span v-else-if="l.wheels_in" class="text-amber-700 font-semibold tabular">{{ l.wheels_in }} / {{ l.qty_out ?? l.set.qty }}</span>
                        <span v-else class="text-ink-500">—</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div v-if="!t.transfers.length" class="card p-8 text-center text-ink-500 mb-4">В ведомости нет перемещений</div>

    <div v-if="t.status === 'loading' && pool.length && can('transfers')" class="card p-5 mb-4">
        <h2 class="text-lg mb-3">Добавить перемещения из пула</h2>
        <div class="flex flex-wrap gap-2">
            <label v-for="p in pool" :key="p.id" class="chip border border-line cursor-pointer" :class="add.transfer_ids.includes(p.id) ? 'bg-blue-600 text-white' : ''">
                <input v-model="add.transfer_ids" type="checkbox" :value="p.id" class="hidden" />
                {{ p.number }} · {{ p.from.code }} → {{ p.to.code }} · {{ p.lines_count }} компл.
            </label>
        </div>
        <button class="btn-secondary mt-3" :disabled="!add.transfer_ids.length || add.processing" @click="add.post(`/trips/${t.id}/transfers`, { preserveScroll: true, onSuccess: () => add.reset() })">Добавить ({{ add.transfer_ids.length }})</button>
    </div>

    <button v-if="t.status === 'loading' && can('transfers')" class="btn-ghost" @click="cancel">Отменить ведомость</button>
</template>

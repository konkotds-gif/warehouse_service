<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import ScanInput from '../../Components/ScanInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { postJson, beep } from '../../lib/http';
import { dt, can } from '../../lib/format';
import { ask } from '../../lib/confirm';

const props = defineProps({ transfer: Object });
const t = computed(() => props.transfer);
// Погрузка идёт на экране ведомости; здесь — только приёмка
const mode = computed(() => (['shipped', 'discrepancy'].includes(t.value.status) ? 'in' : null));
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
async function cancel() {
    if (await ask('Комплекты останутся на месте и снова будут доступны для перемещения.', { title: `Отменить перемещение ${t.value.number}?`, ok: 'Отменить перемещение', cancel: 'Не отменять', danger: true })) router.post(`/transfers/${t.value.id}/cancel`);
}
async function removeLine(l) {
    if (await ask('Комплект останется на месте. Если в перемещении больше ничего нет, оно будет отменено.', { title: `Убрать ${l.set.qr_code} из перемещения?`, ok: 'Убрать', danger: true })) router.delete(`/transfers/${t.value.id}/lines/${l.id}`, { preserveScroll: true });
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
        <p v-if="t.notes" class="text-sm text-ink-500">{{ t.notes }}</p>
    </div>

    <div v-if="t.status === 'created'" class="card p-5 mb-4">
        <div v-if="t.trip" class="flex flex-wrap items-center gap-2">
            <Icon name="truck" /> <span>Включено в ведомость</span>
            <Link :href="`/trips/${t.trip.id}`" class="font-bold text-blue-600 tabular">{{ t.trip.number }}</Link>
            <span class="text-ink-500">: погрузка и сканирование бирок там.</span>
        </div>
        <div v-else class="flex flex-wrap items-center gap-2">
            <Icon name="truck" /> <span>Ожидает водителя в пуле перевозок.</span>
            <Link href="/tasks/transport" class="text-blue-600 font-semibold">Задания → Перевозки →</Link>
        </div>
        <p class="mt-2 text-sm text-ink-500">Бирки сканируются при погрузке, когда приедет водитель и будет сформирована ведомость. До отгрузки в перемещение можно добавлять комплекты других договоров.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <Link :href="`/transfers/create?transfer=${t.id}`" class="btn-blue">+ Добавить комплекты</Link>
            <a :href="`/transfers/${t.id}/print`" target="_blank" class="btn-secondary"><Icon name="printer" /> Накладная водителю</a>
            <button v-if="!t.trip" class="btn-ghost" @click="cancel">Отменить перемещение</button>
        </div>
    </div>
    <div v-else-if="t.status !== 'cancelled'" class="mb-4 flex flex-wrap gap-2">
        <a :href="`/transfers/${t.id}/print`" target="_blank" class="btn-secondary"><Icon name="printer" /> Накладная</a>
        <Link v-if="t.trip" :href="`/trips/${t.trip.id}`" class="btn-ghost">Ведомость {{ t.trip.number }} →</Link>
    </div>

    <div v-if="mode" class="card p-5 mb-4" :class="flash">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="text-lg">{{ mode === 'out' ? 'Погрузка: сканируйте бирки' : 'Приёмка: сканируйте бирки' }}</h2>
            <span class="chip bg-blue-50 text-blue-600 tabular">{{ done }} / {{ t.lines.length }}</span>
        </div>
        <ScanInput @scan="scan" />
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-ink-500">Сканируйте бирку на каждом колесе. Если колёс фактически другое число, нажмите его и отсканируйте бирку один раз:</span>
            <button v-for="n in 8" :key="n" class="h-9 w-9 rounded-[10px] border font-bold" :class="qty === n ? 'bg-amber-500 text-white border-amber-500' : 'border-line'" @click="qty = qty === n ? null : n">{{ n }}</button>
        </div>
        <div v-if="msg" class="mt-3 flex gap-2 font-semibold" :class="msg.ok ? 'text-green-700' : 'text-red-600'"><Icon :name="msg.ok ? 'check' : 'alert'" /> {{ msg.text }}</div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="t.status === 'shipped' && can('receive-transfer')" class="btn-primary" @click="router.post(`/transfers/${t.id}/complete`)">Завершить приёмку</button>
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>QR</th><th>Клиент</th><th>Комплект</th><th>Госномер</th><th>Статус</th><th>Адрес</th><th>Погрузка</th><th>Приёмка</th><th v-if="t.status === 'created' && !t.trip"></th></tr></thead>
            <tbody>
                <tr v-for="l in t.lines" :key="l.id">
                    <td class="font-bold tabular"><a :href="`/contracts/${l.set.contract_id}`" class="text-blue-600">{{ l.set.qr_code }}</a></td>
                    <td>{{ l.client }}</td>
                    <td>{{ l.set.title }}</td>
                    <td class="tabular">{{ l.set.plate }}</td>
                    <td><StatusBadge :status="l.set.status" /></td>
                    <td class="tabular">{{ l.set.address }}</td>
                    <td><span v-if="l.scanned_out" class="text-green-700 font-semibold">✓ {{ l.qty_out }} шт</span><span v-else class="text-ink-500">—</span></td>
                    <td><span v-if="l.scanned_in" class="text-green-700 font-semibold">✓ {{ l.qty_in }} шт</span>
                        <span v-else-if="l.wheels_in" class="text-amber-700 font-semibold tabular">{{ l.wheels_in }} / {{ l.qty_out ?? l.set.qty }}</span>
                        <span v-else class="text-ink-500">—</span></td>
                    <td v-if="t.status === 'created' && !t.trip"><button class="text-xs text-ink-500 underline" @click="removeLine(l)">убрать</button></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import ScanInput from '../../Components/ScanInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { getJson, postJson, beep } from '../../lib/http';
import { money, phone, d } from '../../lib/format';

// Выдача (план, 6.5): скан квитанции → проверка долга → скан бирки → подтверждение количества
const data = ref(null);
const error = ref(null);
const done = ref(null);
const active = ref(null);
const tag = ref('');
const qty = ref(4);
const busy = ref(false);

async function lookup(code) {
    error.value = null;
    done.value = null;
    active.value = null;
    try {
        data.value = await getJson('/w/scan?code=' + encodeURIComponent(code));
        const ready = data.value.sets.filter((s) => s.status === 'ready');
        if (ready.length === 1) start(ready[0]);
        beep(true);
    } catch (e) {
        data.value = null;
        error.value = e.message;
        beep(false);
    }
}
function start(s) {
    active.value = s;
    qty.value = s.qty;
    tag.value = '';
}
async function issue() {
    busy.value = true;
    error.value = null;
    try {
        const res = await postJson(`/sets/${active.value.id}/issue`, { code: tag.value, qty: qty.value });
        done.value = res.message;
        beep(true);
        const s = data.value.sets.find((x) => x.id === active.value.id);
        Object.assign(s, res.set);
        active.value = null;
    } catch (e) {
        error.value = e.message;
        beep(false);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Выдача" />
    <div class="max-w-2xl mx-auto">
        <div class="eyebrow mb-1">Магазин</div>
        <h1 class="text-2xl mb-4">Выдача клиенту</h1>
        <ScanInput placeholder="QR квитанции, № договора или бирки" @scan="lookup" />
        <p class="mt-2 text-sm text-ink-500">Нет квитанции? Найдите клиента через <Link href="/search" class="text-blue-600 font-semibold">поиск</Link> по телефону или госномеру.</p>

        <div v-if="error" class="mt-4 card border-red-600 p-4 flex gap-3"><Icon name="alert" class="text-red-600 flex-none" /> <b>{{ error }}</b></div>
        <div v-if="done" class="mt-4 card border-green-500 p-4 flex gap-3"><Icon name="check" class="text-green-500 flex-none" /> <b>{{ done }}</b></div>

        <div v-if="data" class="mt-4 card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Link :href="`/contracts/${data.contract.id}`" class="text-2xl font-black tabular text-blue-600">№ {{ data.contract.number }}</Link>
                <span class="badge badge-ink">{{ data.contract.status_label }}</span>
            </div>
            <div class="mt-1">{{ data.contract.client }} · <span class="tabular">{{ phone(data.contract.phone) }}</span></div>
            <div class="text-ink-500">{{ data.contract.vehicle }}</div>

            <div class="mt-3 rounded-[12px] p-3" :class="data.debt.blocked ? 'bg-red-50 text-red-600' : data.debt.warnings.length ? 'bg-amber-500/10 text-amber-700' : 'bg-green-500/10 text-green-700'">
                <div class="font-semibold">{{ data.debt.blocked ? `Выдача заблокирована: долг ${money(data.debt.debt)}` : data.debt.warnings.length ? 'Проверьте перед выдачей' : 'Долга нет' }}</div>
                <div v-for="w in data.debt.warnings" :key="w" class="text-sm">{{ w }}</div>
            </div>

            <div v-for="s in data.sets" :key="s.id" class="mt-4 rounded-[12px] border p-4" :class="active?.id === s.id ? 'border-blue-600' : 'border-line'">
                <div class="flex items-center gap-3">
                    <b class="tabular">{{ s.qr_code }}</b><StatusBadge :status="s.status" /><span class="ml-auto tabular text-sm">{{ s.address }}</span>
                </div>
                <div class="text-sm text-ink-500 mt-1">{{ s.title }}</div>
                <div v-if="s.status === 'ordered'" class="text-sm mt-1">Заказан к выдаче в {{ s.issue_store }} на {{ d(s.issue_due_date) }} — ещё не привезли</div>
                <div v-if="['placed', 'received', 'accepted'].includes(s.status)" class="text-sm mt-1">Не заказан к выдаче — <Link :href="`/contracts/${data.contract.id}`" class="text-blue-600 font-semibold">заказать</Link></div>
                <button v-if="s.status === 'ready' && active?.id !== s.id" class="btn-primary mt-3" @click="start(s)">Выдать →</button>
                <div v-if="active?.id === s.id" class="mt-3">
                    <label class="label">Отсканируйте QR на бирке колеса</label>
                    <input v-model="tag" class="input input-lg tabular" autofocus placeholder="QR бирки" @keyup.enter="tag && issue()" />
                    <label class="label mt-3">Колёс на выдачу (по договору {{ s.qty }})</label>
                    <div class="grid grid-cols-8 gap-2">
                        <button v-for="n in 8" :key="n" class="h-12 rounded-[10px] border font-bold" :class="qty === n ? 'bg-blue-600 text-white border-blue-600' : 'border-line'" @click="qty = n">{{ n }}</button>
                    </div>
                    <button class="btn-primary btn-lg w-full mt-4" :disabled="busy || !tag || data.debt.blocked" @click="issue">Выдать {{ qty }} шт</button>
                </div>
            </div>
        </div>
    </div>
</template>

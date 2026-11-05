<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { d, dt, money, phone, today, can } from '../../lib/format';

const props = defineProps({ contract: Object, debt: Object, pricing: Object, extensions: { type: Array, default: () => [] }, movements: Array, prints: Array, stores: Array, otherGarage: Array, autoPrint: Boolean });
const user = computed(() => usePage().props.auth.user);
const statusBadge = { open: 'badge-green', overdue: 'badge-red', closed: 'badge-gray' };

// Печать: форма POST в новой вкладке
const printForm = ref(null);
const print = reactive({ set_id: '', copies: '', reason: 'accept', receipt: 0, action: 'tags' });
function doPrint(opts) {
    Object.assign(print, { set_id: '', copies: '', reason: 'accept', receipt: 0, action: 'tags' }, opts);
    setTimeout(() => printForm.value.submit());
}
const reprint = reactive({ show: false, set: null, copies: 1 });

// Заказ к выдаче
const order = useForm({ store_id: user.value.location?.type === 'store' ? user.value.location.id : props.stores[0]?.id, date: today(1) });
const orderSet = ref(null);
const submitOrder = () => order.post(`/sets/${orderSet.value.id}/order`, { preserveScroll: true, onSuccess: () => (orderSet.value = null) });

// Выдача
const issue = useForm({ code: '', qty: null });
const issueSet = ref(null);
function openIssue(s) {
    issueSet.value = s;
    issue.reset();
    issue.qty = s.qty;
}
const submitIssue = () => issue.post(`/sets/${issueSet.value.id}/issue`, { preserveScroll: true, onSuccess: () => (issueSet.value = null) });

// Продление: окно с расчётом → документ пролонгации → печать
const extend = useForm({ months: 1 });
const showExtend = ref(false);
const extendDone = ref(null); // оформленный документ пролонгации
function openExtend() {
    extend.reset();
    extend.clearErrors();
    extendDone.value = null;
    showExtend.value = true;
}
// Дата + N месяцев без переполнения (31.01 + 1 мес. = 28/29.02), как на сервере
function addMonths(iso, n) {
    const [y, m, day] = iso.split('-').map(Number);
    const target = new Date(Date.UTC(y, m - 1 + n, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(day, last));
    return target.toISOString().slice(0, 10);
}
const extendUntil = computed(() => addMonths(props.pricing.extend_from, extend.months));
const submitExtend = () => extend.post(`/contracts/${props.contract.id}/extend`, {
    preserveScroll: true,
    onSuccess: () => (extendDone.value = props.extensions[0] || null),
});
const extensionPrintUrl = (e) => `/contracts/${props.contract.id}/extensions/${e.id}/print`;

// Списание
const dispose = useForm({ reason: '' });
const disposeSet = ref(null);
const submitDispose = () => dispose.post(`/sets/${disposeSet.value.id}/dispose`, { preserveScroll: true, onSuccess: () => (disposeSet.value = null) });

// Фото
function uploadPhotos(s, e) {
    router.post(`/sets/${s.id}/photos`, { photos: Array.from(e.target.files) }, { forceFormData: true, preserveScroll: true });
}
const allIssued = computed(() => props.contract.sets.every((s) => !['accepted', 'in_transit', 'received', 'placed', 'ordered', 'ready', 'lost'].includes(s.status)));
const totalWheels = computed(() => props.contract.sets.reduce((a, s) => a + s.qty, 0));
</script>

<template>
    <Head :title="`Договор ${contract.number}`" />
    <form ref="printForm" method="post" target="_blank" :action="`/contracts/${contract.id}/${print.action === 'zpl' ? 'tags.zpl' : 'tags'}`" class="hidden">
        <input type="hidden" name="_token" :value="usePage().props.csrf_token || ''" />
        <input v-if="print.set_id" type="hidden" name="set_id" :value="print.set_id" />
        <input v-if="print.copies" type="hidden" name="copies" :value="print.copies" />
        <input type="hidden" name="reason" :value="print.reason" />
        <input type="hidden" name="receipt" :value="print.receipt" />
    </form>

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="eyebrow mb-1">Договор хранения</div>
            <h1 class="text-3xl tabular">№ {{ contract.number }} <span class="badge align-middle" :class="statusBadge[contract.status]">{{ contract.status_label }}</span></h1>
            <p class="mt-1 text-ink-500">Принят {{ dt(contract.accepted_at) }} · {{ contract.store?.label }}<template v-if="contract.accepted_by"> · {{ contract.accepted_by }}</template></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button v-if="can('print')" class="btn-primary" @click="doPrint({ receipt: 1 })"><Icon name="printer" :size="18" /> Бирки ({{ totalWheels }}) и квитанция</button>
            <button v-if="can('print')" class="btn-secondary" @click="doPrint({ action: 'zpl' })">ZPL для принтера</button>
            <a v-if="can('print')" :href="`/contracts/${contract.id}/receipt`" target="_blank" class="btn-secondary">Квитанция</a>
            <button v-if="can('accept') && contract.status !== 'closed'" class="btn-secondary" @click="openExtend">Продлить</button>
        </div>
    </div>

    <div v-if="autoPrint" class="card border-blue-600 p-5 mb-6 flex flex-wrap items-center gap-4">
        <span class="icon-tile"><Icon name="printer" /></span>
        <div class="flex-1"><div class="font-bold text-lg">Напечатайте бирки: {{ totalWheels }} шт.</div><div class="text-ink-500 text-sm">На всех бирках одинаковый QR с номером договора. Наклейте по одной на каждое колесо.</div></div>
        <button class="btn-primary btn-lg" @click="doPrint({ receipt: 1 })">Печать бирок и квитанции →</button>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-4 content-start">
            <div v-for="s in contract.sets" :key="s.id" class="card p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-2xl font-black tabular">{{ s.qr_code }}</span>
                    <StatusBadge :status="s.status" />
                    <span class="ml-auto chip bg-blue-50 text-blue-600 text-sm tabular"><Icon name="map" :size="14" /> {{ s.address }}</span>
                </div>
                <div class="mt-2 text-ink-900">{{ s.qty }} шт · <b>{{ s.tire_size }}</b> · {{ s.with_rims ? 'с дисками' : 'без дисков' }} · {{ s.condition_label }}<template v-if="s.season_label"> · {{ s.season_label }}</template></div>
                <div v-if="s.brand_model || s.notes" class="text-sm text-ink-500">{{ s.brand_model }} {{ s.notes }}</div>
                <div v-if="s.damages_labels?.length" class="text-sm text-amber-700">Повреждения: {{ s.damages_labels.join(', ') }}</div>
                <div v-if="s.issue_store" class="mt-2 text-sm"><Icon name="issue" :size="14" class="inline" /> Выдача в <b>{{ s.issue_store }}</b> на {{ d(s.issue_due_date) }}</div>
                <div v-if="s.photos?.length" class="mt-3 flex gap-2 overflow-x-auto">
                    <a v-for="p in s.photos" :key="p.id" :href="p.url" target="_blank"><img :src="p.url" class="h-20 w-20 rounded-[10px] object-cover border border-line" /></a>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button v-if="s.status === 'ready' && can('issue')" class="btn-primary" @click="openIssue(s)">Выдать →</button>
                    <button v-if="['placed', 'received', 'accepted'].includes(s.status) && can('order-issue')" class="btn-blue" @click="orderSet = s">Заказать к выдаче</button>
                    <button v-if="s.status === 'ordered' && can('order-issue')" class="btn-secondary" @click="router.post(`/sets/${s.id}/cancel-order`, {}, { preserveScroll: true })">Отменить заказ</button>
                    <button v-if="can('print') && !['issued', 'disposed'].includes(s.status)" class="btn-secondary" @click="reprint.set = s; reprint.copies = 1; reprint.show = true">Перепечатать бирку</button>
                    <label v-if="can('accept') && !['issued', 'disposed'].includes(s.status)" class="btn-ghost cursor-pointer"><Icon name="camera" :size="18" /> Фото
                        <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="uploadPhotos(s, $event)" /></label>
                    <button v-if="can('fix') && !['issued', 'disposed'].includes(s.status)" class="btn-ghost text-red-600" @click="disposeSet = s">Списать</button>
                </div>
            </div>

            <div v-if="allIssued && otherGarage.length && can('accept')" class="card border-blue-600 p-5">
                <div class="font-bold">Принять на хранение другой комплект этого автомобиля?</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Link v-for="g in otherGarage" :key="g.id" :href="`/accept?garage_set=${g.id}`" class="btn-link">{{ g.title }} · {{ g.tire_size }} →</Link>
                </div>
            </div>

            <section v-if="extensions.length" class="card p-5">
                <h2 class="text-lg mb-3">Продления (пролонгации)</h2>
                <div class="grid gap-3">
                    <div v-for="e in extensions" :key="e.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line pb-3 last:border-0 last:pb-0">
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold"><span class="tabular">{{ e.number }}</span> от {{ d(e.at) }} · +{{ e.months }} мес.</div>
                            <div class="text-sm tabular">{{ d(e.previous_until) }} → <b>{{ d(e.storage_until) }}</b> · {{ money(e.amount) }}</div>
                            <div class="text-xs text-ink-500">{{ dt(e.at) }} · {{ e.user || 'система' }} ·
                                <span v-if="e.onec_doc_guid" class="text-green-700">в 1С: документ {{ e.onec_doc_guid }}</span>
                                <span v-else-if="e.onec_delivered" class="text-green-700">получено 1С</span>
                                <span v-else class="text-amber-700">ожидает обработки в 1С</span>
                            </div>
                        </div>
                        <a :href="extensionPrintUrl(e)" target="_blank" class="btn-secondary"><Icon name="printer" :size="16" /> Печать</a>
                    </div>
                </div>
            </section>

            <section class="card p-5">
                <h2 class="text-lg mb-3">История движений</h2>
                <ol class="relative border-l-2 border-line ml-2">
                    <li v-for="m in movements" :key="m.id" class="ml-5 pb-4">
                        <span class="absolute -left-[7px] mt-1.5 h-3 w-3 rounded-full" :class="m.action === 'qty_mismatch' ? 'bg-red-600' : 'bg-blue-600'"></span>
                        <div class="flex flex-wrap gap-x-3 items-baseline">
                            <span class="font-semibold">{{ m.action_label }}</span>
                            <span class="text-sm tabular text-ink-500">{{ m.qr_code }}</span>
                            <span class="text-sm tabular" v-if="m.from_address || m.to_address">{{ m.from_address || '—' }} → <b>{{ m.to_address }}</b></span>
                        </div>
                        <div class="text-xs text-ink-500">{{ dt(m.at) }} · {{ m.user || 'система' }}<template v-if="m.qty"> · {{ m.qty }} шт</template></div>
                        <div v-if="m.note" class="text-sm text-ink-500">{{ m.note }}</div>
                    </li>
                </ol>
            </section>
        </div>

        <aside class="grid gap-4 content-start">
            <div class="card p-5">
                <div class="text-sm text-ink-500">Клиент</div>
                <Link v-if="contract.client" :href="`/clients/${contract.client.id}`" class="text-lg font-bold text-blue-600">{{ contract.client.full_name }}</Link>
                <div class="tabular">{{ phone(contract.client?.phone) }}</div>
                <div class="mt-3 text-sm text-ink-500">Автомобиль</div>
                <Link v-if="contract.vehicle && can('clients')" :href="`/vehicles/${contract.vehicle.id}`" class="font-semibold text-blue-600">{{ contract.vehicle.title }}</Link>
                <div v-else class="font-semibold">{{ contract.vehicle?.title || 'не указан' }}</div>
            </div>
            <div class="card p-5" :class="debt.blocked ? 'border-red-600' : ''">
                <div class="flex justify-between"><span class="text-ink-500">Сумма за хранение</span><b>{{ money(contract.amount) }}</b></div>
                <div v-for="s in contract.sets.filter((x) => x.price !== null)" :key="s.id" class="mt-1 text-sm text-ink-500 tabular">
                    {{ s.tire_size }}: {{ money(s.price) }} × {{ s.qty }} кол.{{ contract.months ? ` × ${contract.months} мес.` : '' }}
                </div>
                <div v-if="!contract.sets.some((x) => x.price !== null)" class="flex justify-between gap-3 mt-1"><span class="text-ink-500">Тариф</span><span class="text-right">{{ contract.tariff?.name || '—' }}</span></div>
                <div class="flex justify-between mt-1"><span class="text-ink-500">Срок хранения до</span><span class="tabular">{{ d(contract.storage_until) }}</span></div>
                <div class="flex justify-between mt-1"><span class="text-ink-500">Оплачено до</span><span class="tabular">{{ d(contract.paid_until) }}</span></div>
                <div v-if="debt.overdue.free_until" class="flex justify-between mt-1"><span class="text-ink-500">Бесплатно забрать до</span><span class="tabular">{{ d(debt.overdue.free_until) }}</span></div>
                <div v-if="debt.overdue.due > 0" class="flex justify-between mt-1"><span class="text-ink-500">После срока: {{ debt.overdue.days }} дн.</span><b class="text-red-600 tabular">{{ money(debt.overdue.due) }}</b></div>
                <div v-if="contract.overdue_fee > 0" class="flex justify-between mt-1"><span class="text-ink-500">Начислено за просрочку</span><span class="tabular">{{ money(contract.overdue_fee) }} ({{ contract.overdue_days }} дн.)</span></div>
                <div class="flex justify-between mt-1"><span class="text-ink-500">Долг (1С)</span><b :class="debt.debt > 0 ? 'text-red-600' : ''">{{ money(debt.debt) }}</b></div>
                <div v-for="w in debt.warnings" :key="w" class="mt-2 text-sm flex gap-2 text-amber-700"><Icon name="alert" :size="16" class="flex-none mt-0.5" /> {{ w }}</div>
                <div class="mt-2 text-xs text-ink-500" v-if="contract.onec_doc_guid">Документ 1С: {{ contract.onec_doc_guid }}</div>
            </div>
            <div class="card p-5" v-if="contract.payments.length">
                <div class="font-semibold mb-2">Оплаты (1С)</div>
                <div v-for="(p, i) in contract.payments" :key="i" class="flex justify-between text-sm"><span class="tabular">{{ d(p.paid_at) }}</span><b>{{ money(p.amount) }}</b></div>
            </div>
            <div class="card p-5">
                <div class="font-semibold mb-2">Печать бирок</div>
                <div v-for="(p, i) in prints" :key="i" class="text-sm flex justify-between gap-2"><span>{{ p.reason === 'reprint' ? 'Перепечатка' : 'Приём' }} · {{ p.copies }} шт · {{ p.format.toUpperCase() }}</span><span class="text-ink-500 tabular">{{ dt(p.at) }}</span></div>
                <div v-if="!prints.length" class="text-sm text-ink-500">Ещё не печатались</div>
            </div>
        </aside>
    </div>

    <Modal :show="!!orderSet" title="Заказ к выдаче" @close="orderSet = null">
        <p class="text-sm text-ink-500 mb-4">Комплект {{ orderSet?.qr_code }} ({{ orderSet?.address }}). Склад получит задание «отобрать» со сроком на день раньше.</p>
        <label class="label">Магазин выдачи</label>
        <select v-model="order.store_id" class="input"><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select>
        <label class="label mt-3">Дата визита клиента</label>
        <input v-model="order.date" type="date" class="input" :min="today()" />
        <div v-if="order.errors.domain || order.errors.date" class="error">{{ order.errors.domain || order.errors.date }}</div>
        <button class="btn-primary w-full mt-5" :disabled="order.processing" @click="submitOrder">Заказать →</button>
    </Modal>

    <Modal :show="!!issueSet" title="Выдача клиенту" @close="issueSet = null">
        <div v-if="debt.blocked" class="mb-3 rounded-[12px] bg-red-50 p-3 text-sm text-red-600">Выдача заблокирована: долг {{ money(debt.debt) }}. Клиент должен оплатить в кассе (1С).</div>
        <div v-if="debt.overdue.due > 0" class="mb-3 rounded-[12px] bg-amber-500/10 p-3 text-amber-700">
            <div class="font-semibold">Доплата за хранение после срока: {{ money(debt.overdue.due) }}</div>
            <div class="text-sm">Срок истёк {{ d(debt.overdue.ends_at) }}, бесплатно до {{ d(debt.overdue.free_until) }}; {{ debt.overdue.days }} дн. × {{ money(debt.overdue.rate) }}{{ debt.overdue.per === 'wheel' ? ` × ${debt.overdue.wheels} кол.` : '' }}. Примите оплату в кассе (1С).</div>
        </div>
        <label class="label">Отсканируйте QR на бирке выдаваемого колеса</label>
        <input v-model="issue.code" class="input input-lg tabular" autofocus placeholder="QR бирки" @keyup.enter="submitIssue" />
        <label class="label mt-3">Сколько колёс выдаёте (по договору {{ issueSet?.qty }})</label>
        <div class="grid grid-cols-8 gap-2">
            <button v-for="n in 8" :key="n" type="button" class="h-11 rounded-[10px] border font-bold" :class="issue.qty === n ? 'bg-blue-600 text-white border-blue-600' : 'border-line'" @click="issue.qty = n">{{ n }}</button>
        </div>
        <div v-if="issue.errors.domain || issue.errors.code" class="error">{{ issue.errors.domain || issue.errors.code }}</div>
        <button class="btn-primary btn-lg w-full mt-5" :disabled="issue.processing || !issue.code || debt.blocked" @click="submitIssue">Выдать {{ issue.qty }} шт →</button>
    </Modal>

    <Modal :show="reprint.show" title="Перепечатать бирку" @close="reprint.show = false">
        <p class="text-sm text-ink-500">QR на всех бирках одинаковый — достаточно указать, сколько бирок нужно.</p>
        <label class="label mt-3">Количество бирок</label>
        <input v-model.number="reprint.copies" type="number" min="1" max="8" class="input" />
        <div class="mt-5 flex gap-2">
            <button class="btn-primary flex-1" @click="doPrint({ set_id: reprint.set.id, copies: reprint.copies, reason: 'reprint' }); reprint.show = false">Печать</button>
            <button class="btn-secondary" @click="doPrint({ set_id: reprint.set.id, copies: reprint.copies, reason: 'reprint', action: 'zpl' }); reprint.show = false">ZPL</button>
        </div>
    </Modal>

    <Modal :show="showExtend" :title="extendDone ? 'Пролонгация оформлена' : 'Продлить хранение'" @close="showExtend = false">
        <template v-if="extendDone">
            <div class="rounded-[12px] bg-green-500/10 p-4">
                <div class="text-lg font-bold tabular">{{ extendDone.number }}</div>
                <div class="mt-1">Хранение продлено на {{ extendDone.months }} мес., до <b class="tabular">{{ d(extendDone.storage_until) }}</b></div>
                <div class="mt-1">К оплате: <b class="tabular">{{ money(extendDone.amount) }}</b></div>
            </div>
            <p class="mt-3 text-sm text-ink-500">Документ отправлен в 1С для начисления. Распечатайте пролонгацию и дайте клиенту подписать.</p>
            <div class="mt-5 flex gap-2">
                <a :href="extensionPrintUrl(extendDone)" target="_blank" class="btn-primary flex-1 justify-center"><Icon name="printer" :size="18" /> Печать пролонгации</a>
                <button class="btn-secondary" @click="showExtend = false">Готово</button>
            </div>
        </template>
        <template v-else>
            <label class="label">На сколько месяцев</label>
            <div class="seg w-full grid" :style="{ gridTemplateColumns: `repeat(${pricing.max_months}, minmax(0, 1fr))` }">
                <button v-for="m in pricing.max_months" :key="m" type="button" class="tabular" :class="{ on: extend.months === m }" @click="extend.months = m">{{ m }}</button>
            </div>
            <div v-if="extend.errors.months || extend.errors.domain || extend.errors.sets" class="error">{{ extend.errors.months || extend.errors.domain || extend.errors.sets }}</div>
            <div class="mt-4 grid gap-1 text-sm">
                <div class="flex justify-between"><span class="text-ink-500">Срок хранения сейчас до</span><span class="tabular">{{ d(contract.storage_until) }}</span></div>
                <div class="flex justify-between"><span class="text-ink-500">Продление считается с</span><span class="tabular">{{ d(pricing.extend_from) }}</span></div>
                <div class="flex justify-between"><span class="text-ink-500">Новая дата окончания</span><b class="tabular">{{ d(extendUntil) }}</b></div>
                <div v-if="pricing.extend_month !== null" class="flex justify-between"><span class="text-ink-500">Стоимость по прайсу</span><b class="tabular">{{ money(pricing.extend_month * extend.months) }}</b></div>
            </div>
            <p class="mt-3 text-sm text-ink-500">Будет оформлен документ пролонгации с печатной формой, он уйдёт в 1С для начисления.</p>
            <button class="btn-primary w-full mt-5" :disabled="extend.processing" @click="submitExtend">Оформить пролонгацию на {{ extend.months }} мес.</button>
        </template>
    </Modal>

    <Modal :show="!!disposeSet" title="Списать комплект" @close="disposeSet = null">
        <label class="label">Причина (утилизация по истечении срока, отказ клиента…)</label>
        <input v-model="dispose.reason" class="input" />
        <button class="btn-primary w-full mt-5" :disabled="!dispose.reason || dispose.processing" @click="submitDispose">Списать</button>
    </Modal>
</template>

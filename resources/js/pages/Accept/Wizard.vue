<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TireSizeInput from '../../Components/TireSizeInput.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import CarMakeModel from '../../Components/CarMakeModel.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import PlateInput from '../../Components/PlateInput.vue';
import { formatPhone, phoneComplete, ruPlateComplete, displayPlate } from '../../lib/masks';
import { splitBrandModel, joinBrandModel } from '../../lib/tires';
import { getJson } from '../../lib/http';
import { phone as fmtPhone, money } from '../../lib/format';

const props = defineProps({ stores: Array, defaultStoreId: Number, prices: { type: Array, default: () => [] }, pricing: Object, client: Object, garage: Array, preselect: Object, tireBrands: { type: Array, default: () => [] } });
const seasons = usePage().props.dict.seasons;
const DAMAGES = usePage().props.dict.damages || [];
const DEFAULT_DAMAGES = DAMAGES.filter((x) => x.default).map((x) => x.value);

const steps = ['Клиент', 'Авто / гараж', 'Комплект', 'Проверка'];
const step = ref(props.client ? (props.preselect?.vehicle_id ? 2 : 1) : 0);

// --- Шаг 1: клиент
const q = ref('');
const found = ref([]);
const searching = ref(false);
let timer;
watch(q, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) return (found.value = []);
    timer = setTimeout(async () => {
        searching.value = true;
        try { found.value = await getJson('/w/clients?q=' + encodeURIComponent(v.trim())); } finally { searching.value = false; }
    }, 300);
});
const newClient = reactive({ full_name: '', phone: '', consent_sms: true, consent_pd: true });
const isNewClient = ref(false);
function pickClient(c) {
    router.get('/accept', { client: c.id }, { preserveState: false });
}

// --- Шаг 2: авто и гараж
const vehicleId = ref(props.preselect?.vehicle_id || (props.garage.length === 1 ? props.garage[0].id : null));
const newVehicle = reactive({ plate: '', plate_mode: 'ru', make: '', model: '' });
const plateOk = computed(() => newVehicle.plate_mode === 'none' || (newVehicle.plate_mode === 'ru' ? ruPlateComplete(newVehicle.plate) : newVehicle.plate.trim().length >= 2));
const isNewVehicle = ref(!props.client || !props.garage.length);
const selectedVehicle = computed(() => props.garage.find((v) => v.id === vehicleId.value));

// --- Шаг 3: комплекты
const blank = () => ({ garage_set_id: null, title: '', qty: 4, tire_size: '', with_rims: null, condition: null, season: null, brand: '', model: '', damages: [], notes: '', condition_needs_confirm: false });
// Данные из гаража: brand_model раскладываем на бренд (селект) и модель (текст)
const fromGarage = (prefill) => {
    const { brand_model, ...rest } = prefill || {};
    const s = { ...blank(), ...rest, ...splitBrandModel(brand_model, props.tireBrands) };
    if (s.condition === 'used' && !s.damages?.length) s.damages = [...DEFAULT_DAMAGES];
    return s;
};
const form = useForm({
    store_id: props.defaultStoreId || props.stores[0]?.id || null,
    months: Math.min(6, props.pricing?.max_months || 7),
    notes: '',
    sets: [blank()],
});
function useGarageSet(g) {
    if (g.active_set) return;
    vehicleId.value = g.vehicle_id;
    isNewVehicle.value = false;
    form.sets = [fromGarage(g.prefill)];
    step.value = 2;
}
if (props.preselect?.garage_set_id) {
    const g = props.garage.flatMap((v) => v.garage_sets).find((x) => x.id === props.preselect.garage_set_id);
    if (g && !g.active_set) form.sets = [fromGarage(g.prefill)];
}

const sizeRe = /^(\d{3})\s*\/\s*(\d{2})\s*(Z?R)\s*(\d{2}(\.5)?C?)(\s*\d{2,3}(\/\d{2,3})?\s*[A-Za-z])?$|^\d{2}\s*[xXхХ]\s*\d{1,2}(\.\d{1,2})?\s*R\s*\d{2}(\s*\d{2,3}\s*[A-Za-z])?$/i;
function setErrors(s) {
    const e = [];
    if (!sizeRe.test(s.tire_size.trim())) e.push('размер шин (ширина, профиль, диаметр)');
    if (s.with_rims === null) e.push('с дисками / без');
    if (!s.condition) e.push('БУ / НОВЫЕ');
    return e;
}

// БУ: царапины и потёртости отмечаются сразу; НОВЫЕ: повреждения не нужны
function setCondition(s, c) {
    s.condition = c;
    s.condition_needs_confirm = false;
    if (c === 'used' && !s.damages.length) s.damages = [...DEFAULT_DAMAGES];
    if (c === 'new') s.damages = [];
}
const damagesFor = (s) => DAMAGES.filter((x) => s.with_rims !== false || !x.rim);
const damageLabels = (s) => damagesFor(s).filter((x) => s.damages.includes(x.value)).map((x) => x.label);

const canNext = computed(() => {
    if (step.value === 0) return props.client || (isNewClient.value && newClient.full_name.trim() && phoneComplete(newClient.phone));
    if (step.value === 1) return isNewVehicle.value ? plateOk.value && newVehicle.make.trim() && newVehicle.model.trim() : !!vehicleId.value;
    if (step.value === 2) return form.store_id && form.sets.every((s) => setErrors(s).length === 0) && !missingPrice.value;
    return true;
});
const totalWheels = computed(() => form.sets.reduce((a, s) => a + Number(s.qty || 0), 0));
// Стоимость: цена за колесо в месяц по диаметру × колёса × месяцы
const monthOptions = computed(() => Array.from({ length: props.pricing?.max_months || 7 }, (_, i) => i + 1));
function diameterOf(size) {
    const m = /Z?R\s*(\d{2})(?:[.,]5)?C?\b/i.exec(size || '');
    return m ? Number(m[1]) : null;
}
function priceLine(s) {
    const d = diameterOf(s.tire_size);
    const t = d ? props.prices.find((p) => d >= p.diameter_from && d <= (p.diameter_to || p.diameter_from)) : null;
    return { diameter: d, tariff: t, price: t ? Number(t.price) : null, amount: t ? Number(t.price) * Number(s.qty || 0) * form.months : null };
}
const lines = computed(() => form.sets.map(priceLine));
// Даты договора: сегодня и окончание срока (как на сервере — addMonthsNoOverflow), без бесплатных дней
const fmtDate = (x) => x.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
const startDate = new Date();
const endDate = computed(() => {
    const y = startDate.getFullYear(), m = startDate.getMonth() + form.months;
    const last = new Date(y, m + 1, 0).getDate();
    return new Date(y, m, Math.min(startDate.getDate(), last));
});
const total = computed(() => lines.value.reduce((a, l) => a + (l.amount || 0), 0));
const missingPrice = computed(() => lines.value.find((l) => l.diameter && !l.tariff));

function submit() {
    form.transform((data) => ({
        ...data,
        sets: data.sets.map(({ brand, model, ...s }) => ({ ...s, brand_model: joinBrandModel(brand, model) || null, damages: s.condition === 'used' ? damageLabelsValues(s) : [] })),
        client_id: props.client?.id || null,
        client: props.client ? null : { ...newClient },
        vehicle_id: isNewVehicle.value ? null : vehicleId.value,
        vehicle: isNewVehicle.value ? { plate: newVehicle.plate_mode === 'none' ? null : newVehicle.plate, no_plate: newVehicle.plate_mode === 'none', make: newVehicle.make, model: newVehicle.model } : null,
    })).post('/accept');
}
const damageLabelsValues = (s) => damagesFor(s).filter((x) => s.damages.includes(x.value)).map((x) => x.value);
const allErrors = computed(() => Object.values(form.errors));
</script>

<template>
    <Head title="Приём на хранение" />
    <PageHeader eyebrow="Приём на хранение" title="Новый договор хранения" subtitle="Номер договора присваивается автоматически и печатается в QR на бирках" />

    <!-- Шаги -->
    <div class="mb-6 grid grid-cols-4 gap-2">
        <button v-for="(s, i) in steps" :key="s" type="button" class="text-left" :disabled="i > step" @click="step = i">
            <div class="h-1.5 rounded-full" :class="i <= step ? 'bg-blue-600' : 'bg-line'"></div>
            <div class="mt-2 text-xs sm:text-sm font-semibold" :class="i === step ? 'text-ink-900' : 'text-ink-500'">{{ i + 1 }}. {{ s }}</div>
        </button>
    </div>

    <div v-if="allErrors.length" class="card border-red-600 p-4 mb-4 text-red-600 text-sm">
        <div v-for="(e, i) in allErrors" :key="i">{{ e }}</div>
    </div>

    <!-- 1. Клиент -->
    <section v-if="step === 0" class="card p-5 sm:p-6">
        <div v-if="client" class="flex flex-wrap items-center gap-4">
            <span class="icon-tile"><Icon name="users" /></span>
            <div class="flex-1">
                <div class="text-lg font-bold">{{ client.full_name }}</div>
                <div class="text-ink-500 tabular">{{ fmtPhone(client.phone) }}</div>
            </div>
            <button class="btn-secondary" @click="router.get('/accept', {}, { preserveState: false })">Другой клиент</button>
        </div>
        <template v-else>
            <div class="seg mb-4">
                <button :class="{ on: !isNewClient }" @click="isNewClient = false">Найти клиента</button>
                <button :class="{ on: isNewClient }" @click="isNewClient = true">Новый клиент</button>
            </div>
            <div v-if="!isNewClient">
                <input v-model="q" class="input input-lg" placeholder="Телефон, госномер или ФИО" autofocus />
                <div class="mt-3 text-sm text-ink-500" v-if="searching">Ищем…</div>
                <ul class="mt-3 divide-y divide-line">
                    <li v-for="c in found" :key="c.id">
                        <button class="w-full py-3 flex items-center gap-3 text-left hover:bg-bg rounded-[12px] px-2" @click="pickClient(c)">
                            <div class="flex-1">
                                <div class="font-semibold">{{ c.full_name }}</div>
                                <div class="text-sm text-ink-500 tabular">{{ fmtPhone(c.phone) }}</div>
                            </div>
                            <div class="flex flex-wrap gap-1 justify-end">
                                <span v-for="v in c.vehicles" :key="v.id" class="chip bg-blue-50 text-blue-600">{{ displayPlate(v.plate) }}</span>
                            </div>
                            <Icon name="arrow" :size="18" />
                        </button>
                    </li>
                </ul>
                <p v-if="q.length >= 2 && !found.length && !searching" class="mt-3 text-sm text-ink-500">Не нашли — <button class="text-blue-600 font-semibold" @click="isNewClient = true; newClient.full_name = /\d/.test(q) ? '' : q; newClient.phone = /\d/.test(q) ? formatPhone(q) : ''">создать нового клиента</button></p>
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label">ФИО клиента *</label><input v-model="newClient.full_name" class="input" /></div>
                <div><label class="label">Телефон *</label><PhoneInput v-model="newClient.phone" /></div>
                <div class="flex flex-col justify-end gap-2 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" v-model="newClient.consent_sms" class="h-4 w-4 accent-blue-600" /> Согласие на SMS</label>
                    <label class="flex items-center gap-2"><input type="checkbox" v-model="newClient.consent_pd" class="h-4 w-4 accent-blue-600" /> Согласие на обработку ПДн (152-ФЗ)</label>
                </div>
            </div>
        </template>
    </section>

    <!-- 2. Авто и гараж -->
    <section v-if="step === 1" class="card p-5 sm:p-6">
        <div v-if="garage.length" class="seg mb-4">
            <button :class="{ on: !isNewVehicle }" @click="isNewVehicle = false">Гараж клиента</button>
            <button :class="{ on: isNewVehicle }" @click="isNewVehicle = true">Новый автомобиль</button>
        </div>
        <div v-if="!isNewVehicle" class="grid gap-4 lg:grid-cols-2">
            <div v-for="v in garage" :key="v.id" class="rounded-[16px] border p-4 transition cursor-pointer"
                :class="vehicleId === v.id ? 'border-blue-600 ring-4 ring-blue-600/10' : 'border-line hover:border-blue-600'" @click="vehicleId = v.id">
                <div class="flex items-center gap-3">
                    <span class="icon-tile !h-10 !w-10"><Icon name="car" /></span>
                    <div><div class="font-bold">{{ v.make }} {{ v.model }}</div><div class="tabular text-ink-500">{{ displayPlate(v.plate) }}</div></div>
                </div>
                <div class="mt-3 grid gap-2">
                    <button v-for="g in v.garage_sets" :key="g.id" type="button" :disabled="!!g.active_set"
                        class="text-left rounded-[12px] border border-line px-3 py-2 hover:border-blue-600 disabled:opacity-60 disabled:hover:border-line"
                        @click.stop="useGarageSet(g)">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{ g.title }}</span>
                            <span class="badge" :class="g.active_set ? 'badge-green' : 'badge-ink'">{{ g.active_set ? 'На хранении ' + g.active_set.qr_code : g.state_label }}</span>
                        </div>
                        <div class="text-sm text-ink-500">{{ g.qty }} шт · {{ g.tire_size }} · {{ g.with_rims ? 'с дисками' : 'без дисков' }} · {{ g.condition_label }}</div>
                        <div v-if="!g.active_set" class="text-xs text-blue-600 font-semibold mt-1">Принять этот комплект — данные подставятся →</div>
                    </button>
                    <div v-if="!v.garage_sets.length" class="text-sm text-ink-500">Комплектов в гараже нет — заполните на следующем шаге</div>
                </div>
            </div>
        </div>
        <div v-else class="grid gap-4 sm:grid-cols-3">
            <div><label class="label">Госномер *</label><PlateInput v-model="newVehicle.plate" v-model:mode="newVehicle.plate_mode" /></div>
            <CarMakeModel v-model:make="newVehicle.make" v-model:model="newVehicle.model" required />
        </div>
    </section>

    <!-- 3. Комплект -->
    <section v-if="step === 2" class="grid gap-4">
        <div class="card p-5 sm:p-6 grid gap-4 sm:grid-cols-[1fr_2fr]">
            <div><label class="label">Магазин приёма</label>
                <select v-model="form.store_id" class="input"><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.label }}</option></select></div>
            <div>
                <label class="label">Срок хранения, мес.</label>
                <div class="seg w-full grid" :style="{ gridTemplateColumns: `repeat(${monthOptions.length}, minmax(0, 1fr))` }">
                    <button v-for="m in monthOptions" :key="m" type="button" class="tabular" :class="{ on: form.months === m }" @click="form.months = m">{{ m }}</button>
                </div>
                <div class="mt-2 text-sm">Договор: <b class="tabular">с {{ fmtDate(startDate) }} по {{ fmtDate(endDate) }}</b></div>
                <div class="error" v-if="form.errors.months">{{ form.errors.months }}</div>
            </div>
        </div>

        <div v-for="(s, i) in form.sets" :key="i" class="card p-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg">Комплект {{ form.sets.length > 1 ? i + 1 : '' }} <span v-if="s.garage_set_id" class="badge badge-blue ml-2">из гаража</span></h3>
                <button v-if="form.sets.length > 1" class="btn-ghost !min-h-9" @click="form.sets.splice(i, 1)"><Icon name="x" :size="16" /> Убрать</button>
            </div>
            <label class="label">Количество колёс * <span class="text-ink-500 font-normal">(бирок будет столько же)</span></label>
            <div class="grid grid-cols-8 gap-2 max-w-md">
                <button v-for="n in 8" :key="n" type="button" class="h-12 rounded-[12px] border font-bold tabular"
                    :class="s.qty === n ? 'bg-blue-600 text-white border-blue-600' : 'border-line hover:border-blue-600'" @click="s.qty = n">{{ n }}</button>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Размер шин *</label>
                    <TireSizeInput v-model="s.tire_size" />
                </div>
                <div><label class="label">Сезон</label>
                    <select v-model="s.season" class="input"><option :value="null">—</option><option v-for="x in seasons" :key="x.value" :value="x.value">{{ x.label }}</option></select></div>
                <div>
                    <label class="label">Диски *</label>
                    <div class="seg w-full grid grid-cols-2"><button type="button" :class="{ on: s.with_rims === true }" @click="s.with_rims = true">С дисками</button><button type="button" :class="{ on: s.with_rims === false }" @click="s.with_rims = false">Без дисков</button></div>
                </div>
                <div>
                    <label class="label">Состояние *</label>
                    <div class="seg w-full grid grid-cols-2" :class="s.condition_needs_confirm ? 'ring-4 ring-amber-500/40 border-amber-500' : ''">
                        <button type="button" :class="{ on: s.condition === 'used' }" @click="setCondition(s, 'used')">БУ</button>
                        <button type="button" :class="{ on: s.condition === 'new' }" @click="setCondition(s, 'new')">НОВЫЕ</button>
                    </div>
                    <div v-if="s.condition_needs_confirm" class="mt-1 text-sm text-amber-700">В прошлый раз были НОВЫЕ и стояли на машине — подставлено БУ. Подтвердите.</div>
                </div>
                <div><label class="label">Бренд шин</label><SearchSelect placeholder="Начните вводить бренд" v-model="s.brand" :options="tireBrands" /></div>
                <div><label class="label">Модель шин</label><input v-model="s.model" class="input" placeholder="Hakkapeliitta 10" /></div>
                <div v-if="s.condition === 'used'" class="sm:col-span-2">
                    <label class="label">Повреждения</label>
                    <div class="grid gap-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                        <label v-for="x in damagesFor(s)" :key="x.value" class="flex items-center gap-2 rounded-[12px] border px-3 py-2 text-sm cursor-pointer"
                            :class="s.damages.includes(x.value) ? 'border-blue-600 bg-blue-50' : 'border-line hover:border-blue-600'">
                            <input type="checkbox" v-model="s.damages" :value="x.value" class="h-4 w-4 accent-blue-600" /> {{ x.label }}
                        </label>
                    </div>
                </div>
                <div class="sm:col-span-2"><label class="label">{{ s.condition === 'used' ? 'Комментарий к повреждениям' : 'Комментарий' }}</label><input v-model="s.notes" class="input" placeholder="Например: порез на левом заднем" /></div>
            </div>
            <div v-if="lines[i].tariff" class="mt-4 rounded-[12px] bg-bg px-4 py-3 flex flex-wrap items-baseline gap-x-2">
                <span class="text-ink-500">Хранение R{{ lines[i].diameter }}:</span>
                <span class="tabular">{{ money(lines[i].price) }} × {{ s.qty }} кол. × {{ form.months }} мес. =</span>
                <b class="tabular text-lg">{{ money(lines[i].amount) }}</b>
            </div>
            <div v-else-if="lines[i].diameter" class="mt-4 rounded-[12px] bg-red-50 px-4 py-3 text-sm text-red-600">Нет цены хранения для R{{ lines[i].diameter }} — администратор добавляет её в «Тарифах».</div>
            <div v-if="setErrors(s).length" class="mt-3 text-sm text-ink-500">Осталось указать: {{ setErrors(s).join(', ') }}</div>
        </div>
        <button v-if="form.sets.length < 4" class="btn-secondary justify-self-start" @click="form.sets.push(blank())"><Icon name="accept" :size="18" /> Ещё комплект по этому договору</button>
    </section>

    <!-- 4. Проверка -->
    <section v-if="step === 3" class="card p-5 sm:p-6">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-ink-500">Клиент</dt><dd class="font-semibold">{{ client?.full_name || newClient.full_name }} · {{ fmtPhone(client?.phone) !== '—' ? fmtPhone(client?.phone) : newClient.phone }}</dd></div>
            <div><dt class="text-sm text-ink-500">Автомобиль</dt><dd class="font-semibold">{{ isNewVehicle ? `${newVehicle.make} ${newVehicle.model} ${newVehicle.plate_mode === 'none' ? 'без номера' : newVehicle.plate}` : `${selectedVehicle?.make} ${selectedVehicle?.model} ${displayPlate(selectedVehicle?.plate)}` }}</dd></div>
            <div><dt class="text-sm text-ink-500">Магазин приёма</dt><dd class="font-semibold">{{ stores.find((s) => s.id === form.store_id)?.label }}</dd></div>
            <div><dt class="text-sm text-ink-500">Срок и стоимость хранения</dt><dd class="font-semibold">{{ form.months }} мес. · <span class="tabular">{{ money(total) }}</span></dd></div>
            <div><dt class="text-sm text-ink-500">Срок договора</dt><dd class="font-semibold tabular">с {{ fmtDate(startDate) }} по {{ fmtDate(endDate) }}</dd></div>
        </dl>
        <div class="mt-4 rounded-[12px] bg-bg p-4" v-for="(s, i) in form.sets" :key="i">
            <b>{{ s.qty }} шт</b> · {{ s.tire_size.toUpperCase() }} · {{ s.with_rims ? 'с дисками' : 'без дисков' }} · {{ s.condition === 'new' ? 'НОВЫЕ' : 'БУ' }}
            <span v-if="s.season" class="text-ink-500"> · {{ seasons.find((x) => x.value === s.season)?.label }}</span>
            <span v-if="s.brand || s.model" class="text-ink-500"> · {{ joinBrandModel(s.brand, s.model) }}</span>
            <div v-if="s.condition === 'used' && damageLabels(s).length" class="text-sm text-ink-500 mt-1">Повреждения: {{ damageLabels(s).join(', ') }}</div>
            <div v-if="lines[i].tariff" class="text-sm mt-1"><span class="text-ink-500">{{ lines[i].tariff.code }} · {{ money(lines[i].price) }} × {{ s.qty }} кол. × {{ form.months }} мес. =</span> <b class="tabular">{{ money(lines[i].amount) }}</b></div>
        </div>
        <div class="mt-4 flex items-baseline justify-between rounded-[12px] border border-line px-4 py-3">
            <span class="font-semibold">Итого к оплате за хранение</span><b class="text-2xl tabular">{{ money(total) }}</b>
        </div>
        <p class="mt-2 text-sm text-ink-500">После окончания срока — {{ pricing.free_days }} дн. бесплатно, затем {{ money(pricing.overdue_rate) }} в день{{ pricing.overdue_per === 'wheel' ? ' за каждое колесо' : '' }}.</p>
        <div class="mt-4"><label class="label">Комментарий к договору</label><input v-model="form.notes" class="input" /></div>
        <p class="mt-4 text-sm text-ink-500">После оформления откроется печать: {{ totalWheels }} бирок с одинаковым QR (номер договора) и квитанция клиенту.</p>
    </section>

    <!-- Навигация -->
    <div class="sticky bottom-20 lg:bottom-4 mt-6 flex gap-3">
        <button v-if="step > 0" class="btn-secondary btn-lg" @click="step--">Назад</button>
        <button v-if="step < 3" class="btn-primary btn-lg flex-1 sm:flex-none" :disabled="!canNext" @click="step++">Далее <Icon name="arrow" :size="18" /></button>
        <button v-else class="btn-primary btn-lg flex-1 sm:flex-none" :disabled="form.processing" @click="submit">Принять на хранение <Icon name="arrow" :size="18" /></button>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Modal from '../../Components/Modal.vue';
import { money } from '../../lib/format';

const props = defineProps({ tariffs: Array, pricing: Object });

const prices = computed(() => props.tariffs.filter((t) => t.diameter_from));
const other = computed(() => props.tariffs.filter((t) => !t.diameter_from));
const months = computed(() => Array.from({ length: props.pricing.max_months }, (_, i) => i + 1));
const diam = (t) => (t.diameter_to && t.diameter_to !== t.diameter_from ? `R${t.diameter_from}–R${t.diameter_to}` : `R${t.diameter_from}`);

const editing = ref(null);
const blankTariff = () => ({ code: '', name: '', diameter_from: null, diameter_to: null, price: 0, period: 'month', months: 1, is_active: true });
const form = useForm(blankTariff());
function open(t = null) {
    editing.value = t || {};
    form.clearErrors();
    Object.assign(form, t
        ? { code: t.code, name: t.name, diameter_from: t.diameter_from, diameter_to: t.diameter_to, price: Number(t.price), period: t.period, months: t.months, is_active: t.is_active }
        : blankTariff());
}
const save = () => (editing.value.id
    ? form.put(`/admin/tariffs/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) })
    : form.post('/admin/tariffs', { preserveScroll: true, onSuccess: () => (editing.value = null) }));

const terms = useForm({ ...props.pricing });
const saveTerms = () => terms.put('/admin/tariffs/pricing', { preserveScroll: true });
const example = computed(() => {
    const per = terms.overdue_per === 'wheel' ? 4 : 1;
    return 10 * Number(terms.overdue_rate || 0) * per;
});
</script>

<template>
    <Head title="Тарифы" />
    <PageHeader eyebrow="Администрирование" title="Тарифы хранения"
        subtitle="Цена за 1 колесо в месяц по диаметру. Стоимость = цена × колёса × месяцы. Код — номенклатура 1С.">
        <button class="btn-primary" @click="open()">Добавить</button>
    </PageHeader>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr><th>Диаметр</th><th>Номенклатура 1С</th><th class="text-right">Колесо / мес.</th>
                    <th v-for="m in months" :key="m" class="text-right whitespace-nowrap">{{ m }} мес.</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="t in prices" :key="t.id" :class="!t.is_active ? 'opacity-50' : ''">
                    <td class="font-semibold whitespace-nowrap">{{ diam(t) }}</td>
                    <td><div class="tabular font-semibold whitespace-nowrap">{{ t.code }}</div><div class="text-xs text-ink-500 whitespace-nowrap">{{ t.name }}</div></td>
                    <td class="tabular text-right font-semibold">{{ money(t.price) }}</td>
                    <td v-for="m in months" :key="m" class="tabular text-right text-ink-500">{{ money(t.price * m) }}</td>
                    <td><button class="btn-ghost !min-h-8" @click="open(t)">Изменить</button></td>
                </tr>
                <tr v-if="!prices.length"><td :colspan="4 + months.length" class="text-ink-500">Прайс пуст — добавьте цены по диаметрам</td></tr>
            </tbody>
        </table>
    </div>

    <section class="card p-5 sm:p-6 mt-6">
        <h2 class="text-lg">Условия хранения</h2>
        <p class="text-sm text-ink-500 mt-1">После окончания срока клиент может забрать колёса бесплатно в течение указанных дней, дальше начисляется плата за каждый день.
            Сумма показывается при выдаче и уходит в 1С с событием выдачи.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div><label class="label">Срок хранения, до … мес.</label><input v-model.number="terms.max_months" type="number" min="1" max="24" class="input" />
                <div class="error" v-if="terms.errors.max_months">{{ terms.errors.max_months }}</div></div>
            <div><label class="label">Бесплатных дней после срока</label><input v-model.number="terms.free_days" type="number" min="0" class="input" />
                <div class="error" v-if="terms.errors.free_days">{{ terms.errors.free_days }}</div></div>
            <div><label class="label">Плата за день, ₽</label><input v-model.number="terms.overdue_rate" type="number" min="0" class="input" />
                <div class="error" v-if="terms.errors.overdue_rate">{{ terms.errors.overdue_rate }}</div></div>
            <div><label class="label">Плата берётся</label>
                <select v-model="terms.overdue_per" class="input"><option value="contract">за договор в день</option><option value="wheel">за каждое колесо в день</option></select></div>
            <div><label class="label">Код 1С услуги «просрочка»</label><input v-model="terms.overdue_service_code" class="input" placeholder="необязательно" /></div>
        </div>
        <p class="mt-3 text-sm text-ink-500">Пример: 4 колеса забрали через {{ terms.free_days + 10 }} дн. после окончания срока — 10 платных дней = <b>{{ money(example) }}</b>.</p>
        <button class="btn-primary mt-4" :disabled="terms.processing" @click="saveTerms">Сохранить условия</button>
    </section>

    <div v-if="other.length" class="card overflow-x-auto mt-6">
        <div class="px-4 pt-4 text-sm text-ink-500">Прочие услуги (без диаметра) — при приёме не используются</div>
        <table class="table">
            <thead><tr><th>Код</th><th>Наименование</th><th>Цена</th><th>1С</th><th></th></tr></thead>
            <tbody>
                <tr v-for="t in other" :key="t.id" :class="!t.is_active ? 'opacity-50' : ''">
                    <td class="font-semibold">{{ t.code }}</td><td>{{ t.name }}</td><td class="tabular">{{ money(t.price) }}</td>
                    <td><span class="badge" :class="t.onec_guid ? 'badge-green' : 'badge-ink'">{{ t.onec_guid ? 'из 1С' : 'вручную' }}</span></td>
                    <td><button class="btn-ghost !min-h-8" @click="open(t)">Изменить</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :show="!!editing" title="Цена хранения" @close="editing = null">
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Диаметр от (R)</label><input v-model.number="form.diameter_from" type="number" min="10" max="30" class="input" placeholder="16" />
                <div class="error" v-if="form.errors.diameter_from">{{ form.errors.diameter_from }}</div></div>
            <div><label class="label">Диаметр до (R)</label><input v-model.number="form.diameter_to" type="number" min="10" max="30" class="input" :placeholder="form.diameter_from || 'как «от»'" />
                <div class="error" v-if="form.errors.diameter_to">{{ form.errors.diameter_to }}</div></div>
            <div><label class="label">Код номенклатуры 1С</label><input v-model="form.code" class="input" placeholder="ЦБ000335" /><div class="error" v-if="form.errors.code">{{ form.errors.code }}</div></div>
            <div><label class="label">Цена за 1 колесо в месяц, ₽</label><input v-model.number="form.price" type="number" min="0" class="input" />
                <div class="error" v-if="form.errors.price">{{ form.errors.price }}</div></div>
            <div class="sm:col-span-2"><label class="label">Наименование</label><input v-model="form.name" class="input" placeholder="R-16 - сезонное хранение шины" />
                <div class="error" v-if="form.errors.name">{{ form.errors.name }}</div></div>
            <label class="flex gap-2 text-sm sm:col-span-2"><input type="checkbox" v-model="form.is_active" class="accent-blue-600" /> Действует</label>
            <button class="btn-primary sm:col-span-2" :disabled="form.processing" @click="save">Сохранить</button>
        </div>
    </Modal>
</template>

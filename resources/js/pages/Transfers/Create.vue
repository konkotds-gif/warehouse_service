<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ locations: Array, from: Object, to: Object, candidates: Array, target: Object, drafts: Array });
const form = useForm({ from_location_id: props.from?.id || null, to_location_id: props.to?.id || null, set_ids: [], driver: '' });
const filter = ref('all');
const groups = { all: 'Все', accepted: 'Буфер магазина', ordered: 'Заказаны к выдаче', placed: 'В ячейках', ready: 'Готовы к выдаче (возврат)' };
const list = computed(() => props.candidates.filter((s) => filter.value === 'all' || s.status === filter.value));
function reload() {
    router.get('/transfers/create', { from: form.from_location_id, to: form.to_location_id || undefined }, { preserveState: false });
}
function toggleAll() {
    const ids = list.value.map((s) => s.id);
    const all = ids.every((id) => form.set_ids.includes(id));
    form.set_ids = all ? form.set_ids.filter((id) => !ids.includes(id)) : [...new Set([...form.set_ids, ...ids])];
}
function submit() {
    if (props.target) form.post(`/transfers/${props.target.id}/sets`);
    else form.post('/transfers');
}
function suggestFor() {
    // Для заказанных к выдаче — подставить магазин назначения
    const ordered = props.candidates.filter((s) => s.status === 'ordered' && form.set_ids.includes(s.id));
    if (ordered.length && !form.to_location_id) form.to_location_id = ordered[0].issue_store_id;
}
</script>

<template>
    <Head :title="target ? `Дополнить ${target.number}` : 'Новое перемещение'" />
    <PageHeader eyebrow="Логистика" :title="target ? `Дополнить перемещение ${target.number}` : 'Новое перемещение'"
        subtitle="Выберите комплекты одного или нескольких договоров. Сканировать сейчас не нужно: перемещение попадёт в «Задания → Перевозки», бирки сканируются при погрузке водителю." />
    <div v-if="!target && drafts?.length" class="card p-4 mb-4 border-amber-500">
        <div class="font-semibold mb-2">Из этой точки уже есть перемещения, ожидающие погрузки. Можно дополнить их:</div>
        <div class="flex flex-wrap gap-2">
            <Link v-for="dr in drafts" :key="dr.id" :href="`/transfers/create?transfer=${dr.id}`" class="chip border border-line hover:bg-blue-50">
                <b class="tabular">{{ dr.number }}</b> {{ dr.from.code }} → {{ dr.to.code }} · {{ dr.lines_count }} компл.<template v-if="dr.trip"> · в ведомости {{ dr.trip.number }}</template>
            </Link>
        </div>
    </div>
    <div class="card p-5 grid gap-4 sm:grid-cols-3">
        <div><label class="label">Откуда</label>
            <select v-model="form.from_location_id" class="input" :disabled="!!target" @change="reload"><option :value="null">—</option><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.label }}</option></select></div>
        <div><label class="label">Куда</label>
            <select v-model="form.to_location_id" class="input" :disabled="!!target"><option :value="null">—</option><option v-for="l in locations.filter((x) => x.id !== form.from_location_id)" :key="l.id" :value="l.id">{{ l.label }}</option></select></div>
        <div v-if="!target"><label class="label">Водитель (если уже известен)</label><input v-model="form.driver" class="input" /></div>
    </div>

    <div v-if="from" class="mt-4 card overflow-x-auto">
        <div class="flex flex-wrap items-center gap-2 p-4">
            <div class="seg">
                <button v-for="(label, key) in groups" :key="key" :class="{ on: filter === key }" @click="filter = key">{{ label }}</button>
            </div>
            <button class="btn-ghost ml-auto" @click="toggleAll">Выбрать все</button>
        </div>
        <table class="table">
            <thead><tr><th></th><th>QR</th><th>Статус</th><th>Адрес</th><th>Комплект</th><th>Госномер</th><th>Выдача</th></tr></thead>
            <tbody>
                <tr v-for="s in list" :key="s.id" class="cursor-pointer" @click="form.set_ids.includes(s.id) ? form.set_ids.splice(form.set_ids.indexOf(s.id), 1) : form.set_ids.push(s.id); suggestFor()">
                    <td><input type="checkbox" class="h-5 w-5 accent-blue-600" :checked="form.set_ids.includes(s.id)" @click.stop @change="form.set_ids.includes(s.id) ? form.set_ids.splice(form.set_ids.indexOf(s.id), 1) : form.set_ids.push(s.id); suggestFor()" /></td>
                    <td class="font-bold tabular">{{ s.qr_code }}</td>
                    <td><StatusBadge :status="s.status" /></td>
                    <td class="tabular">{{ s.address }}</td>
                    <td>{{ s.title }}</td>
                    <td class="tabular">{{ s.plate }}</td>
                    <td>{{ s.issue_store ? `${s.issue_store}, ${s.issue_due_date}` : '' }}</td>
                </tr>
                <tr v-if="!list.length"><td colspan="7" class="text-center text-ink-500 py-8">Нет комплектов для отправки</td></tr>
            </tbody>
        </table>
    </div>
    <div v-if="form.errors.domain" class="error mt-3">{{ form.errors.domain }}</div>
    <div class="sticky bottom-20 lg:bottom-4 mt-4">
        <button class="btn-primary btn-lg" :disabled="!form.set_ids.length || !form.to_location_id || form.processing" @click="submit">
            {{ target ? 'Добавить в' : 'Создать перемещение' }}{{ target ? ` ${target.number}` : '' }} ({{ form.set_ids.length }}) →
        </button>
    </div>
</template>

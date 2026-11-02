<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Modal from '../../Components/Modal.vue';
import { money } from '../../lib/format';

defineProps({ tariffs: Array });
const editing = ref(null);
const form = useForm({ code: '', name: '', price: 0, period: 'season', months: 6, is_active: true });
function open(t = null) {
    editing.value = t || {};
    Object.assign(form, t ? { code: t.code, name: t.name, price: t.price, period: t.period, months: t.months, is_active: t.is_active } : { code: '', name: '', price: 0, period: 'season', months: 6, is_active: true });
}
const save = () => (editing.value.id ? form.put(`/admin/tariffs/${editing.value.id}`, { onSuccess: () => (editing.value = null) }) : form.post('/admin/tariffs', { onSuccess: () => (editing.value = null) }));
</script>

<template>
    <Head title="Тарифы" />
    <PageHeader eyebrow="Администрирование" title="Тарифы хранения" subtitle="Обычно приходят из 1С (PUT /api/v1/services). Здесь можно поправить вручную.">
        <button class="btn-primary" @click="open()">Добавить</button>
    </PageHeader>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Код</th><th>Наименование</th><th>Цена</th><th>Период</th><th>Срок</th><th>1С</th><th></th></tr></thead>
            <tbody>
                <tr v-for="t in tariffs" :key="t.id" :class="!t.is_active ? 'opacity-50' : ''">
                    <td class="font-semibold">{{ t.code }}</td><td>{{ t.name }}</td><td class="tabular">{{ money(t.price) }}</td>
                    <td>{{ t.period === 'month' ? 'за месяц' : 'за сезон' }}</td><td>{{ t.months }} мес.</td>
                    <td><span class="badge" :class="t.onec_guid ? 'badge-green' : 'badge-ink'">{{ t.onec_guid ? 'из 1С' : 'вручную' }}</span></td>
                    <td><button class="btn-ghost !min-h-8" @click="open(t)">Изменить</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Modal :show="!!editing" title="Тариф" @close="editing = null">
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Код</label><input v-model="form.code" class="input" /><div class="error" v-if="form.errors.code">{{ form.errors.code }}</div></div>
            <div><label class="label">Цена, ₽</label><input v-model.number="form.price" type="number" min="0" class="input" /></div>
            <div class="sm:col-span-2"><label class="label">Наименование</label><input v-model="form.name" class="input" /></div>
            <div><label class="label">Период</label><select v-model="form.period" class="input"><option value="season">за сезон</option><option value="month">за месяц</option></select></div>
            <div><label class="label">Срок по умолчанию, мес.</label><input v-model.number="form.months" type="number" min="1" max="24" class="input" /></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="accent-blue-600" /> Действует</label>
            <button class="btn-primary sm:col-span-2" @click="save">Сохранить</button>
        </div>
    </Modal>
</template>

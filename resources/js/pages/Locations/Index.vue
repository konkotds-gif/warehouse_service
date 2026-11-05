<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Modal from '../../Components/Modal.vue';
import { can } from '../../lib/format';

defineProps({ locations: Array });
const show = ref(false);
const form = useForm({ code: '', name: '', type: 'warehouse', address: '', is_active: true });
</script>

<template>
    <Head title="Склады и магазины" />
    <PageHeader eyebrow="Справочник" title="Склады и магазины" subtitle="Адрес хранения XX_YY_ZZ = склад_ряд_ячейка. Магазины — буферы М<код>.">
        <button v-if="can('admin')" class="btn-primary" @click="show = true">Добавить локацию</button>
    </PageHeader>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link v-for="l in locations" :key="l.id" :href="`/locations/${l.id}`" class="card card-hover p-5" :class="!l.is_active ? 'opacity-60' : ''">
            <div class="flex items-center justify-between">
                <span class="text-2xl font-black tabular">{{ l.code }}</span>
                <span class="badge" :class="l.type === 'warehouse' ? 'badge-blue' : 'badge-ink'">{{ l.type_label }}</span>
            </div>
            <div class="font-semibold mt-1">{{ l.name }}</div>
            <div class="text-sm text-ink-500">{{ l.address }}</div>
            <div v-if="l.same_building?.length" class="mt-2"><span class="badge badge-blue">Одно здание с {{ l.same_building.join(', ') }}</span></div>
            <div v-if="l.type === 'warehouse'" class="mt-3">
                <div class="flex justify-between text-sm"><span>Занято</span><span class="tabular">{{ l.occupied }} / {{ l.capacity }}</span></div>
                <div class="mt-1 h-2 rounded-full bg-line overflow-hidden"><div class="h-full bg-blue-600" :style="{ width: (l.capacity ? Math.min(100, (l.occupied / l.capacity) * 100) : 0) + '%' }"></div></div>
            </div>
            <div v-else class="mt-3 text-sm">В буфере: <b>{{ l.sets }}</b></div>
        </Link>
    </div>
    <Modal :show="show" title="Новая локация" @close="show = false">
        <div class="grid gap-3">
            <div class="seg"><button :class="{ on: form.type === 'warehouse' }" @click="form.type = 'warehouse'">Склад</button><button :class="{ on: form.type === 'store' }" @click="form.type = 'store'">Магазин</button></div>
            <div><label class="label">Код {{ form.type === 'warehouse' ? '(две цифры, XX в адресе)' : '(М + номер)' }}</label><input v-model="form.code" class="input tabular" :placeholder="form.type === 'warehouse' ? '02' : 'М05'" /><div class="error" v-if="form.errors.code">{{ form.errors.code }}</div></div>
            <div><label class="label">Название</label><input v-model="form.name" class="input" /></div>
            <div><label class="label">Адрес</label><input v-model="form.address" class="input" /></div>
            <button class="btn-primary" @click="form.post('/locations')">Создать</button>
        </div>
    </Modal>
</template>

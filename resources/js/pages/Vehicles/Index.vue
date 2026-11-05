<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import Modal from '../../Components/Modal.vue';
import ClientPicker from '../../Components/ClientPicker.vue';
import VehicleFields from '../../Components/VehicleFields.vue';
import { displayPlate } from '../../lib/masks';
import { phone } from '../../lib/format';
import { emptyVehicle, vehiclePayload } from '../../lib/vehicle';

const props = defineProps({ vehicles: Object, filters: Object });
const f = reactive({ q: props.filters.q || '', only: props.filters.only || '' });
const apply = () => router.get('/vehicles', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });

const show = ref(false);
const owner = ref(null);
const form = useForm({ ...emptyVehicle(), client_id: null });
function create() {
    form.transform((d) => ({ ...vehiclePayload(d), client_id: owner.value?.id }))
        .post('/vehicles', { onSuccess: () => { show.value = false; form.reset(); owner.value = null; } });
}
</script>

<template>
    <Head title="Автомобили" />
    <PageHeader eyebrow="Справочник" title="Автомобили" subtitle="Машины клиентов, их комплекты и договоры хранения">
        <button class="btn-secondary" @click="show = true">Новый автомобиль</button>
    </PageHeader>
    <form class="card p-4 grid gap-3 sm:grid-cols-[1fr_220px_auto]" @submit.prevent="apply">
        <input v-model="f.q" class="input" placeholder="Госномер, VIN, марка, модель, владелец или телефон" />
        <select v-model="f.only" class="input" @change="apply">
            <option value="">Все автомобили</option>
            <option value="stored">Колёса на хранении</option>
            <option value="no_plate">Без номеров</option>
        </select>
        <button class="btn-secondary">Найти</button>
    </form>
    <div class="mt-4 card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Госномер</th><th>Автомобиль</th><th>Владелец</th><th>В гараже</th><th>На хранении</th><th>Договоров</th></tr></thead>
            <tbody>
                <tr v-for="v in vehicles.data" :key="v.id">
                    <td><Link :href="`/vehicles/${v.id}`" class="font-bold text-blue-600 tabular">{{ v.plate ? displayPlate(v.plate) : 'без номеров' }}</Link></td>
                    <td>{{ v.make }} {{ v.model }}<span v-if="v.year" class="text-ink-500"> · {{ v.year }}</span><div v-if="v.vin" class="text-xs text-ink-500 tabular">{{ v.vin }}</div></td>
                    <td><Link v-if="v.client" :href="`/clients/${v.client.id}`" class="text-blue-600">{{ v.client.full_name }}</Link><div v-if="v.client?.phone" class="text-xs text-ink-500 tabular">{{ phone(v.client.phone) }}</div></td>
                    <td>{{ v.garage_sets_count }}</td>
                    <td><span v-if="v.stored_sets" class="badge badge-green">{{ v.stored_sets }} компл.</span><span v-else class="text-ink-500">—</span></td>
                    <td>{{ v.contracts_count }}</td>
                </tr>
                <tr v-if="!vehicles.data.length"><td colspan="6" class="text-center text-ink-500 py-8">Автомобилей не найдено</td></tr>
            </tbody>
        </table>
    </div>
    <Pagination :links="vehicles.links" />

    <Modal :show="show" title="Новый автомобиль" @close="show = false">
        <div class="grid gap-4">
            <div><label class="label">Владелец *</label><ClientPicker v-model="owner" /><div class="error" v-if="form.errors.client_id">{{ form.errors.client_id }}</div></div>
            <VehicleFields :form="form" />
            <div class="error" v-if="form.errors.domain">{{ form.errors.domain }}</div>
            <button class="btn-primary" :disabled="form.processing || !owner" @click="create">Добавить</button>
        </div>
    </Modal>
</template>

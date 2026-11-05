<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import Modal from '../../Components/Modal.vue';
import { phone } from '../../lib/format';

const props = defineProps({ clients: Object, q: String });
const q = ref(props.q);
const show = ref(false);
const form = useForm({ full_name: '', phone: '', email: '', consent_sms: true, consent_pd: true });
</script>

<template>
    <Head title="Клиенты" />
    <PageHeader eyebrow="Справочник" title="Клиенты" subtitle="Контрагенты связаны с 1С по GUID">
        <button class="btn-secondary" @click="show = true">Новый клиент</button>
    </PageHeader>
    <form class="card p-4 flex gap-3" @submit.prevent="router.get('/clients', { q })">
        <input v-model="q" class="input" placeholder="ФИО, телефон или госномер" />
        <button class="btn-secondary">Найти</button>
    </form>
    <div class="mt-4 card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Клиент</th><th>Телефон</th><th>Машин</th><th>1С</th></tr></thead>
            <tbody>
                <tr v-for="c in clients.data" :key="c.id">
                    <td><Link :href="`/clients/${c.id}`" class="font-semibold text-blue-600">{{ c.full_name }}</Link></td>
                    <td class="tabular">{{ phone(c.phone) }}</td>
                    <td>{{ c.vehicles_count }}</td>
                    <td><span class="badge" :class="c.onec_guid ? 'badge-green' : 'badge-amber'">{{ c.onec_guid ? 'связан' : 'ждёт 1С' }}</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Pagination :links="clients.links" />
    <Modal :show="show" title="Новый клиент" @close="show = false">
        <div class="grid gap-3">
            <div><label class="label">ФИО *</label><input v-model="form.full_name" class="input" /><div class="error" v-if="form.errors.full_name">{{ form.errors.full_name }}</div></div>
            <div><label class="label">Телефон</label><PhoneInput v-model="form.phone" /></div>
            <div><label class="label">E-mail</label><input v-model="form.email" class="input" /></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="form.consent_sms" class="accent-blue-600" /> Согласие на SMS</label>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="form.consent_pd" class="accent-blue-600" /> Согласие на обработку ПДн</label>
            <button class="btn-primary" :disabled="form.processing" @click="form.post('/clients')">Создать</button>
        </div>
    </Modal>
</template>

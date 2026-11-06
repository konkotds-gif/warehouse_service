<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import ClientPicker from '../../Components/ClientPicker.vue';
import VehicleFields from '../../Components/VehicleFields.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import { displayPlate } from '../../lib/masks';
import { d, dt, phone, can } from '../../lib/format';
import { ask } from '../../lib/confirm';
import { vehicleToForm, vehiclePayload } from '../../lib/vehicle';

const props = defineProps({ vehicle: Object, client: Object, garageSets: Array, contracts: Array, owners: Array, inStorage: Boolean, persons: { type: Array, default: () => [] } });

// Доверенные лица (семейный аккаунт): существующий клиент или новый по телефону и имени
const showPerson = ref(false);
const personMode = ref('find');
const personClient = ref(null);
const person = useForm({ client_id: null, phone: '', full_name: '', note: '', all_vehicles: false });
function addPerson() {
    person.transform((x) => (personMode.value === 'find' ? { client_id: personClient.value?.id, note: x.note, all_vehicles: x.all_vehicles } : { phone: x.phone, full_name: x.full_name, note: x.note, all_vehicles: x.all_vehicles }))
        .post(`/vehicles/${props.vehicle.id}/persons`, { preserveScroll: true, onSuccess: () => { showPerson.value = false; personClient.value = null; person.reset(); } });
}
async function revokePerson(p) {
    const text = p.all_vehicles
        ? `${p.client?.full_name} потеряет доступ ко всем автомобилям владельца: не сможет сдавать и забирать их колёса и не увидит их в онлайн-записи.`
        : `${p.client?.full_name} больше не сможет сдавать и забирать колёса этой машины и не увидит её в онлайн-записи.`;
    if (await ask(text, { title: 'Отозвать доверенность?', ok: 'Отозвать', danger: true })) {
        router.delete(`/vehicles/${props.vehicle.id}/persons/${p.id}`, { preserveScroll: true });
    }
}
const badge = { open: 'badge-green', overdue: 'badge-red', closed: 'badge-gray' };

const showEdit = ref(false);
const edit = useForm(vehicleToForm(props.vehicle));
function save() {
    edit.transform(vehiclePayload).put(`/vehicles/${props.vehicle.id}`, { preserveScroll: true, onSuccess: () => (showEdit.value = false) });
}

const showOwner = ref(false);
const newOwner = ref(null);
const owner = useForm({ client_id: null, sets_moved: true, note: '' });
function changeOwner() {
    owner.transform((x) => ({ ...x, client_id: newOwner.value?.id }))
        .post(`/vehicles/${props.vehicle.id}/owner`, { preserveScroll: true, onSuccess: () => { showOwner.value = false; newOwner.value = null; owner.reset(); } });
}

async function destroy() {
    if (await ask('Карточка исчезнет из гаража клиента. Договоры хранения сохранятся.', { title: 'Удалить автомобиль?', ok: 'Удалить', danger: true })) {
        router.delete(`/vehicles/${props.vehicle.id}`);
    }
}
</script>

<template>
    <Head :title="vehicle.plate ? displayPlate(vehicle.plate) : vehicle.title" />
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="icon-tile"><Icon name="car" /></span>
            <div>
                <div class="eyebrow mb-1"><Link href="/vehicles" class="hover:underline">Автомобили</Link></div>
                <h1 class="text-3xl">{{ vehicle.make }} {{ vehicle.model }}</h1>
                <p class="text-ink-500 tabular">{{ vehicle.plate ? displayPlate(vehicle.plate) : 'без номеров' }}<template v-if="vehicle.year"> · {{ vehicle.year }} г.</template><template v-if="vehicle.color"> · {{ vehicle.color }}</template></p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <Link v-if="can('accept') && client" :href="`/accept?client=${client.id}&vehicle=${vehicle.id}`" class="btn-primary">Принять на хранение →</Link>
            <button class="btn-secondary" @click="showEdit = true">Изменить</button>
            <button class="btn-secondary" @click="showOwner = true">Сменить владельца</button>
            <button v-if="can('fix') && !inStorage" class="btn-ghost" @click="destroy">Удалить</button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card p-5">
            <h2 class="text-lg mb-3">Владелец</h2>
            <template v-if="client">
                <Link :href="`/clients/${client.id}`" class="text-lg font-bold text-blue-600">{{ client.full_name }}</Link>
                <div class="text-ink-500 tabular">{{ phone(client.phone) }}</div>
                <div v-if="client.email" class="text-ink-500">{{ client.email }}</div>
            </template>
            <div v-else class="text-ink-500">Не указан</div>
        </div>
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg">Доверенные лица</h2>
                <button v-if="client" class="btn-ghost !min-h-8 !text-xs" @click="showPerson = true">+ Добавить</button>
            </div>
            <div v-for="p in persons" :key="p.id" class="flex items-start justify-between gap-2 py-2 border-b border-line last:border-0">
                <div>
                    <Link v-if="p.client" :href="`/clients/${p.client.id}`" class="font-semibold text-blue-600">{{ p.client.full_name }}</Link>
                    <span v-if="p.all_vehicles" class="badge badge-blue ml-1">все автомобили</span>
                    <div class="text-sm text-ink-500 tabular">{{ phone(p.client?.phone) }}</div>
                    <div class="text-xs text-ink-500">добавил {{ p.source_label }} · {{ d(p.created_at) }}<template v-if="p.note"> · {{ p.note }}</template></div>
                </div>
                <button class="btn-ghost !min-h-8 !text-xs text-red-600" @click="revokePerson(p)">Отозвать</button>
            </div>
            <p v-if="!persons.length" class="text-sm text-ink-500">Нет. Доверенное лицо (например, супруга) сможет сдавать колёса этой машины на свой договор и забирать их, а в онлайн-записи увидит её хранения.</p>
        </div>
        <div class="card p-5">
            <h2 class="text-lg mb-3">Данные автомобиля</h2>
            <dl class="grid grid-cols-[140px_1fr] gap-y-2 text-sm">
                <dt class="text-ink-500">Госномер</dt><dd class="tabular font-semibold">{{ vehicle.plate ? displayPlate(vehicle.plate) : 'без номеров' }}</dd>
                <dt class="text-ink-500">Марка, модель</dt><dd>{{ vehicle.make }} {{ vehicle.model }}</dd>
                <dt class="text-ink-500">VIN / кузов</dt><dd class="tabular">{{ vehicle.vin || '—' }}</dd>
                <dt class="text-ink-500">Год выпуска</dt><dd>{{ vehicle.year || '—' }}</dd>
                <dt class="text-ink-500">Цвет</dt><dd>{{ vehicle.color || '—' }}</dd>
                <dt class="text-ink-500">Примечание</dt><dd>{{ vehicle.notes || '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="flex items-center justify-between mt-8 mb-3">
        <h2 class="text-xl">Комплекты в гараже</h2>
        <Link v-if="client" :href="`/clients/${client.id}`" class="btn-link">Управлять в карточке клиента →</Link>
    </div>
    <div class="grid gap-3 lg:grid-cols-2">
        <div v-for="g in garageSets" :key="g.id" class="card p-4" :class="g.state === 'retired' ? 'opacity-50' : ''">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-semibold">{{ g.title }}</span>
                <span v-if="g.active_set" class="flex items-center gap-2"><StatusBadge :status="g.active_set.status" /> <Link :href="`/contracts/${g.active_set.contract_id}`" class="tabular text-blue-600 font-semibold">{{ g.active_set.qr_code }} · {{ g.active_set.address }}</Link></span>
                <span v-else class="badge badge-ink">{{ g.state_label }}</span>
            </div>
            <div class="text-sm text-ink-500">{{ g.qty }} шт · {{ g.tire_size }} · {{ g.with_rims ? 'с дисками' : 'без дисков' }} · {{ g.condition_label }}<template v-if="g.season_label"> · {{ g.season_label }}</template><template v-if="g.brand_model"> · {{ g.brand_model }}</template></div>
            <Link v-if="!g.active_set && g.state !== 'retired' && can('accept')" :href="`/accept?garage_set=${g.id}`" class="btn-link !min-h-8 !text-xs mt-2">Принять →</Link>
        </div>
        <div v-if="!garageSets.length" class="card p-6 text-center text-ink-500 lg:col-span-2">Комплектов пока нет — добавятся при первом приёме.</div>
    </div>

    <h2 class="text-xl mt-8 mb-3">Договоры хранения</h2>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Договор</th><th>Статус</th><th>Клиент</th><th>Комплекты</th><th>Принят</th><th>Срок до</th></tr></thead>
            <tbody>
                <tr v-for="c in contracts" :key="c.id">
                    <td><Link :href="`/contracts/${c.id}`" class="font-bold text-blue-600 tabular">{{ c.number }}</Link></td>
                    <td><span class="badge" :class="badge[c.status]">{{ c.status_label }}</span></td>
                    <td><Link v-if="c.client" :href="`/clients/${c.client.id}`" class="text-blue-600">{{ c.client.full_name }}</Link></td>
                    <td><div v-for="s in c.sets" :key="s.id" class="flex gap-2 items-center"><StatusBadge :status="s.status" /><span class="tabular">{{ s.address }}</span></div></td>
                    <td class="tabular">{{ d(c.accepted_at) }} · {{ c.store?.code }}</td>
                    <td class="tabular">{{ d(c.storage_until) }}</td>
                </tr>
                <tr v-if="!contracts.length"><td colspan="6" class="text-center text-ink-500 py-6">Договоров нет</td></tr>
            </tbody>
        </table>
    </div>

    <template v-if="owners.length">
        <h2 class="text-xl mt-8 mb-3">История владельцев</h2>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Дата</th><th>Был</th><th>Стал</th><th>Комплекты</th><th>Кто</th><th>Примечание</th></tr></thead>
                <tbody>
                    <tr v-for="o in owners" :key="o.id">
                        <td class="tabular">{{ dt(o.at) }}</td>
                        <td><Link v-if="o.from" :href="`/clients/${o.from.id}`" class="text-blue-600">{{ o.from.full_name }}</Link></td>
                        <td><Link v-if="o.to" :href="`/clients/${o.to.id}`" class="text-blue-600">{{ o.to.full_name }}</Link></td>
                        <td>{{ o.sets_moved ? 'перешли с авто' : 'остались у прежнего' }}</td>
                        <td>{{ o.user }}</td>
                        <td>{{ o.note }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>

    <Modal :show="showEdit" title="Данные автомобиля" @close="showEdit = false">
        <div class="grid gap-4">
            <VehicleFields :form="edit" />
            <div class="error" v-if="edit.errors.domain">{{ edit.errors.domain }}</div>
            <button class="btn-primary" :disabled="edit.processing" @click="save">Сохранить</button>
        </div>
    </Modal>
    <Modal :show="showPerson" title="Доверенное лицо" @close="showPerson = false">
        <div class="grid gap-4">
            <div class="seg">
                <button :class="{ on: personMode === 'find' }" @click="personMode = 'find'">Найти клиента</button>
                <button :class="{ on: personMode === 'new' }" @click="personMode = 'new'">По телефону</button>
            </div>
            <div v-if="personMode === 'find'"><label class="label">Клиент</label><ClientPicker v-model="personClient" :exclude-id="client?.id" /></div>
            <template v-else>
                <div><label class="label">Телефон</label><PhoneInput v-model="person.phone" /><div class="error" v-if="person.errors.phone">{{ person.errors.phone }}</div></div>
                <div><label class="label">ФИО (если клиента с таким телефоном ещё нет)</label><input v-model="person.full_name" class="input" maxlength="255" /><div class="error" v-if="person.errors.full_name">{{ person.errors.full_name }}</div></div>
            </template>
            <div><label class="label">Примечание</label><input v-model="person.note" class="input" placeholder="Например: супруга, владелец согласен лично" maxlength="255" /></div>
            <label class="flex items-start gap-2 text-sm"><input v-model="person.all_vehicles" type="checkbox" class="mt-1" />
                <span><b>Все автомобили владельца</b> — доверенное лицо увидит все машины {{ client?.full_name }}, в том числе те, что появятся позже.</span></label>
            <p class="text-xs text-ink-500">Добавляйте только с согласия владельца {{ client?.full_name }}. Владелец может сам добавлять и убирать доверенных лиц в личном кабинете онлайн-записи.</p>
            <div class="error" v-if="person.errors.domain">{{ person.errors.domain }}</div>
            <button class="btn-primary" :disabled="person.processing || (personMode === 'find' ? !personClient : !person.phone)" @click="addPerson">Добавить</button>
        </div>
    </Modal>
    <Modal :show="showOwner" title="Сменить владельца" @close="showOwner = false">
        <div class="grid gap-4">
            <p v-if="inStorage" class="text-sm text-amber-700">Колёса этого автомобиля сейчас на хранении. Сначала выдайте их прежнему владельцу.</p>
            <div><label class="label">Новый владелец</label><ClientPicker v-model="newOwner" :exclude-id="client?.id" /><div class="error" v-if="owner.errors.client_id">{{ owner.errors.client_id }}</div></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="owner.sets_moved" class="accent-blue-600" /> Комплекты из гаража переходят вместе с автомобилем</label>
            <p v-if="!owner.sets_moved" class="text-xs text-ink-500">Комплекты будут отмечены «продан / утилизирован» и пропадут из подсказок при приёме. Если прежний владелец переставит их на другую машину, перенесите их заранее в карточке клиента.</p>
            <div><label class="label">Примечание</label><input v-model="owner.note" class="input" placeholder="Например: продал авто" maxlength="255" /></div>
            <p class="text-xs text-ink-500">Прошлые договоры хранения останутся за прежним владельцем. Доверенные лица прежнего владельца будут отозваны.</p>
            <div class="error" v-if="owner.errors.domain">{{ owner.errors.domain }}</div>
            <button class="btn-primary" :disabled="owner.processing || !newOwner" @click="changeOwner">Сменить владельца</button>
        </div>
    </Modal>
</template>

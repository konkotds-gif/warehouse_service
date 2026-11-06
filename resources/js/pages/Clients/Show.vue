<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TireSizeInput from '../../Components/TireSizeInput.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import CarMakeModel from '../../Components/CarMakeModel.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import PlateInput from '../../Components/PlateInput.vue';
import { displayPlate } from '../../lib/masks';
import { splitBrandModel, joinBrandModel } from '../../lib/tires';
import { d, phone, can } from '../../lib/format';

const props = defineProps({ client: Object, garage: Array, contracts: Array, tireBrands: { type: Array, default: () => [] } });
const seasons = usePage().props.dict.seasons;
const edit = useForm({ ...props.client });
const showEdit = ref(false);
const vehicle = useForm({ plate: '', plate_mode: 'ru', make: '', model: '' });
function addVehicle() {
    vehicle.transform(({ plate_mode, ...d }) => ({ ...d, plate: plate_mode === 'none' ? null : d.plate, no_plate: plate_mode === 'none' }))
        .post(`/clients/${props.client.id}/vehicles`, { onSuccess: () => { showVehicle.value = false; vehicle.reset(); } });
}
const showVehicle = ref(false);
const gs = useForm({ title: '', season: null, qty: 4, tire_size: '', with_rims: true, condition: 'used', brand: '', model: '', notes: '' });
const gsEdit = ref(null);
function openGs(g) {
    gsEdit.value = g;
    Object.assign(gs, { title: g.title, season: g.season, qty: g.qty, tire_size: g.tire_size, with_rims: g.with_rims, condition: g.condition, ...splitBrandModel(g.brand_model, props.tireBrands), notes: g.notes || '' });
}
function saveGs() {
    gs.transform(({ brand, model, ...d }) => ({ ...d, brand_model: joinBrandModel(brand, model) || null }))
        .put(`/garage-sets/${gsEdit.value.id}`, { preserveScroll: true, onSuccess: () => (gsEdit.value = null) });
}
const move = reactive({ set: null, vehicle_id: null });
const merge = useForm({ other_id: '' });
const showMerge = ref(false);
const badge = { open: 'badge-green', overdue: 'badge-red', closed: 'badge-gray' };
</script>

<template>
    <Head :title="client.full_name" />
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="eyebrow mb-1">Клиент</div>
            <h1 class="text-3xl">{{ client.full_name }}</h1>
            <p class="text-ink-500 tabular">{{ phone(client.phone) }}<template v-if="client.email"> · {{ client.email }}</template>
                · <span :class="client.onec_guid ? 'text-green-700' : 'text-amber-700'">{{ client.onec_guid ? 'связан с 1С' : 'ждёт GUID из 1С' }}</span></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <Link v-if="can('accept')" :href="`/accept?client=${client.id}`" class="btn-primary">Принять на хранение →</Link>
            <button class="btn-secondary" @click="showEdit = true">Изменить</button>
            <button v-if="can('fix')" class="btn-ghost" @click="showMerge = true">Объединить дубль</button>
        </div>
    </div>

    <div class="flex items-center justify-between mb-3">
        <h2 class="text-xl">Гараж клиента</h2>
        <button class="btn-secondary !min-h-9" @click="showVehicle = true"><Icon name="car" :size="18" /> Добавить автомобиль</button>
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        <div v-for="v in garage" :key="v.id" class="card p-5">
            <div class="flex items-center gap-3">
                <span class="icon-tile"><Icon name="car" /></span>
                <div><Link :href="`/vehicles/${v.id}`" class="text-lg font-bold text-blue-600">{{ v.make }} {{ v.model }}</Link><div class="tabular text-ink-500">{{ v.plate ? displayPlate(v.plate) : 'без номеров' }}<template v-if="v.year"> · {{ v.year }}</template></div>
                    <div v-if="v.access === 'trusted'" class="text-xs text-ink-500 mt-0.5"><span class="badge badge-blue">доверенное лицо</span> владелец <Link :href="`/clients/${v.owner?.id}`" class="text-blue-600">{{ v.owner?.full_name }}</Link></div></div>
            </div>
            <div class="mt-4 grid gap-2">
                <div v-for="g in v.garage_sets" :key="g.id" class="rounded-[12px] border border-line p-3" :class="g.state === 'retired' ? 'opacity-50' : ''">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">{{ g.title }}</span>
                        <span v-if="g.active_set" class="flex items-center gap-2"><StatusBadge :status="g.active_set.status" /> <Link :href="`/contracts/${g.active_set.contract_id}`" class="tabular text-blue-600 font-semibold">{{ g.active_set.qr_code }} · {{ g.active_set.address }}</Link></span>
                        <span v-else class="badge badge-ink">{{ g.state_label }}</span>
                    </div>
                    <div class="text-sm text-ink-500">{{ g.qty }} шт · {{ g.tire_size }} · {{ g.with_rims ? 'с дисками' : 'без дисков' }} · {{ g.condition_label }}<template v-if="g.season_label"> · {{ g.season_label }}</template></div>
                    <div v-if="g.damages_labels?.length" class="text-xs text-ink-500">Повреждения: {{ g.damages_labels.join(', ') }}</div>
                    <div class="mt-2 flex flex-wrap gap-2" v-if="g.state !== 'retired'">
                        <Link v-if="!g.active_set && can('accept')" :href="`/accept?garage_set=${g.id}`" class="btn-link !min-h-8 !text-xs">Принять →</Link>
                        <button class="btn-ghost !min-h-8 !text-xs" @click="openGs(g)">Изменить</button>
                        <button v-if="v.access !== 'trusted' && garage.filter((x) => x.access !== 'trusted').length > 1" class="btn-ghost !min-h-8 !text-xs" @click="move.set = g; move.vehicle_id = null">На другую машину</button>
                        <button v-if="!g.active_set" class="btn-ghost !min-h-8 !text-xs" @click="router.post(`/garage-sets/${g.id}/retire`, {}, { preserveScroll: true })">Продан / утилизирован</button>
                    </div>
                </div>
                <div v-if="!v.garage_sets.length" class="text-sm text-ink-500">Комплектов пока нет — добавятся при первом приёме.</div>
            </div>
        </div>
        <div v-if="!garage.length" class="card p-8 text-center text-ink-500">Автомобилей нет</div>
    </div>

    <h2 class="text-xl mt-8 mb-3">Договоры</h2>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Договор</th><th>Статус</th><th>Авто</th><th>Комплекты</th><th>Принят</th><th>Срок до</th></tr></thead>
            <tbody>
                <tr v-for="c in contracts" :key="c.id">
                    <td><Link :href="`/contracts/${c.id}`" class="font-bold text-blue-600 tabular">{{ c.number }}</Link></td>
                    <td><span class="badge" :class="badge[c.status]">{{ c.status_label }}</span></td>
                    <td class="tabular"><Link v-if="c.vehicle" :href="`/vehicles/${c.vehicle.id}`" class="text-blue-600">{{ c.vehicle.plate ? displayPlate(c.vehicle.plate) : c.vehicle.title }}</Link></td>
                    <td><div v-for="s in c.sets" :key="s.id" class="flex gap-2 items-center"><StatusBadge :status="s.status" /><span class="tabular">{{ s.address }}</span></div></td>
                    <td class="tabular">{{ d(c.accepted_at) }} · {{ c.store?.code }}</td>
                    <td class="tabular">{{ d(c.storage_until) }}</td>
                </tr>
                <tr v-if="!contracts.length"><td colspan="6" class="text-center text-ink-500 py-6">Договоров нет</td></tr>
            </tbody>
        </table>
    </div>

    <Modal :show="showEdit" title="Данные клиента" @close="showEdit = false">
        <div class="grid gap-3">
            <div><label class="label">ФИО</label><input v-model="edit.full_name" class="input" /></div>
            <div><label class="label">Телефон</label><PhoneInput v-model="edit.phone" /></div>
            <div><label class="label">E-mail</label><input v-model="edit.email" class="input" /><div class="error" v-if="edit.errors.email">{{ edit.errors.email }}</div></div>
            <div><label class="label">Примечание</label><input v-model="edit.notes" class="input" /></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="edit.consent_sms" class="accent-blue-600" /> Согласие на SMS</label>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="edit.consent_pd" class="accent-blue-600" /> Согласие на обработку ПДн</label>
            <button class="btn-primary" @click="edit.put(`/clients/${client.id}`, { onSuccess: () => (showEdit = false) })">Сохранить</button>
        </div>
    </Modal>
    <Modal :show="showVehicle" title="Новый автомобиль" @close="showVehicle = false">
        <div class="grid gap-3">
            <div><label class="label">Госномер</label><PlateInput v-model="vehicle.plate" v-model:mode="vehicle.plate_mode" /><div class="error" v-if="vehicle.errors.plate">{{ vehicle.errors.plate }}</div></div>
            <CarMakeModel v-model:make="vehicle.make" v-model:model="vehicle.model" required />
            <button class="btn-primary" @click="addVehicle">Добавить</button>
        </div>
    </Modal>
    <Modal :show="!!gsEdit" title="Комплект в гараже" @close="gsEdit = null">
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2"><label class="label">Название</label><input v-model="gs.title" class="input" /></div>
            <div class="sm:col-span-2"><label class="label">Размер</label><TireSizeInput v-model="gs.tire_size" /><div class="error" v-if="gs.errors.tire_size">{{ gs.errors.tire_size }}</div></div>
            <div><label class="label">Колёс</label><input v-model.number="gs.qty" type="number" min="1" max="8" class="input" /></div>
            <div><label class="label">Сезон</label><select v-model="gs.season" class="input"><option :value="null">—</option><option v-for="s in seasons" :key="s.value" :value="s.value">{{ s.label }}</option></select></div>
            <div><label class="label">Состояние</label><select v-model="gs.condition" class="input"><option value="used">БУ</option><option value="new">НОВЫЕ</option></select></div>
            <label class="flex gap-2 text-sm items-center"><input type="checkbox" v-model="gs.with_rims" class="accent-blue-600" /> С дисками</label>
            <div><label class="label">Бренд</label><SearchSelect placeholder="Начните вводить бренд" v-model="gs.brand" :options="tireBrands" /></div>
            <div><label class="label">Модель</label><input v-model="gs.model" class="input" /></div>
            <button class="btn-primary sm:col-span-2" @click="saveGs">Сохранить</button>
        </div>
    </Modal>
    <Modal :show="!!move.set" title="Перенести комплект на другую машину" @close="move.set = null">
        <select v-model="move.vehicle_id" class="input"><option :value="null">Выберите автомобиль</option><option v-for="v in garage.filter((x) => x.id !== move.set?.vehicle_id && x.access !== 'trusted')" :key="v.id" :value="v.id">{{ v.title }}</option></select>
        <button class="btn-primary w-full mt-4" :disabled="!move.vehicle_id" @click="router.post(`/garage-sets/${move.set.id}/move`, { vehicle_id: move.vehicle_id }, { preserveScroll: true, onSuccess: () => (move.set = null) })">Перенести</button>
    </Modal>
    <Modal :show="showMerge" title="Объединить с дублем" @close="showMerge = false">
        <p class="text-sm text-ink-500 mb-3">Укажите ID клиента-дубля (из адреса его карточки). Его машины и договоры перейдут сюда, дубль будет удалён.</p>
        <input v-model="merge.other_id" class="input" placeholder="ID клиента" />
        <div class="error" v-if="merge.errors.other_id || merge.errors.domain">{{ merge.errors.other_id || merge.errors.domain }}</div>
        <button class="btn-primary w-full mt-4" @click="merge.post(`/clients/${client.id}/merge`, { onSuccess: () => (showMerge = false) })">Объединить</button>
    </Modal>
</template>

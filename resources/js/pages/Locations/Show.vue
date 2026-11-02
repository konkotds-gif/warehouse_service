<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { can } from '../../lib/format';

const props = defineProps({ location: Object, rows: Array, buffer: Array });
const gen = useForm({ row_from: (props.rows.length ? Number(props.rows.at(-1).row_no) + 1 : 1), row_to: (props.rows.length ? Number(props.rows.at(-1).row_no) + 1 : 1), cells: 20, cell_from: 1, capacity: 1 });
const showGen = ref(false);
const edit = useForm({ code: props.location.code, name: props.location.name, type: props.location.type, address: props.location.address, is_active: props.location.is_active });
const showEdit = ref(false);
const cell = ref(null);
const cellForm = useForm({ capacity: 1, is_blocked: false });
function openCell(c) {
    if (!can('admin')) return;
    cell.value = c;
    cellForm.capacity = c.capacity;
    cellForm.is_blocked = c.is_blocked;
}
</script>

<template>
    <Head :title="location.label" />
    <PageHeader :eyebrow="location.type_label" :title="location.label" :subtitle="location.address">
        <a v-if="location.type === 'warehouse' && can('print')" :href="`/locations/${location.id}/labels`" target="_blank" class="btn-secondary">Этикетки ячеек</a>
        <a v-if="location.type === 'warehouse' && can('print')" :href="`/locations/${location.id}/labels?format=zpl`" class="btn-secondary">ZPL</a>
        <button v-if="location.type === 'warehouse' && can('admin')" class="btn-primary" @click="showGen = true">Добавить ряды и ячейки</button>
        <button v-if="can('admin')" class="btn-ghost" @click="showEdit = true">Изменить</button>
    </PageHeader>

    <div v-if="location.type === 'warehouse'" class="grid gap-4">
        <div v-for="r in rows" :key="r.id" class="card p-4">
            <div class="flex items-center justify-between mb-3">
                <h3>Склад {{ location.code }} · Ряд {{ r.row_no }}</h3>
                <a v-if="can('print')" :href="`/locations/${location.id}/labels?row=${r.row_no}`" target="_blank" class="btn-link !min-h-8 !text-xs">Этикетки ряда</a>
            </div>
            <div class="grid gap-1.5" style="grid-template-columns: repeat(auto-fill, minmax(64px, 1fr))">
                <button v-for="c in r.cells" :key="c.id" type="button" class="rounded-[10px] border px-1 py-2 text-center text-xs tabular transition"
                    :class="c.is_blocked ? 'bg-slate-200 border-slate-300 text-slate-400' : c.sets.length >= c.capacity ? 'bg-blue-600 border-blue-600 text-white' : c.sets.length ? 'bg-blue-50 border-blue-600 text-blue-600' : 'bg-white border-line hover:border-blue-600'"
                    :title="c.address + (c.sets.length ? ': ' + c.sets.map((s) => s.qr_code).join(', ') : '')" @click="c.sets.length === 1 && !can('admin') ? null : openCell(c)">
                    <div class="font-bold">{{ c.cell_no }}</div>
                    <div class="truncate">{{ c.sets.length ? c.sets[0].qr_code : c.is_blocked ? 'блок' : '·' }}</div>
                </button>
            </div>
        </div>
        <div v-if="!rows.length" class="card p-8 text-center text-ink-500">Ячейки ещё не заведены</div>
    </div>

    <h2 class="text-xl mt-8 mb-3">{{ location.type === 'warehouse' ? 'На складе без ячейки' : 'Буфер магазина' }}</h2>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>QR</th><th>Статус</th><th>Комплект</th><th>Госномер</th><th>Выдача</th></tr></thead>
            <tbody>
                <tr v-for="s in buffer" :key="s.id">
                    <td><Link :href="`/contracts/${s.contract_id}`" class="font-bold text-blue-600 tabular">{{ s.qr_code }}</Link></td>
                    <td><StatusBadge :status="s.status" /></td><td>{{ s.title }}</td><td class="tabular">{{ s.plate }}</td>
                    <td>{{ s.issue_store ? `${s.issue_store}, ${s.issue_due_date}` : '' }}</td>
                </tr>
                <tr v-if="!buffer.length"><td colspan="5" class="text-center text-ink-500 py-6">Пусто</td></tr>
            </tbody>
        </table>
    </div>

    <Modal :show="showGen" title="Добавить ряды и ячейки" @close="showGen = false">
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Ряд с</label><input v-model.number="gen.row_from" type="number" min="1" max="99" class="input" /></div>
            <div><label class="label">Ряд по</label><input v-model.number="gen.row_to" type="number" min="1" max="99" class="input" /></div>
            <div><label class="label">Ячеек в ряду</label><input v-model.number="gen.cells" type="number" min="1" max="99" class="input" /></div>
            <div><label class="label">Нумерация ячеек с</label><input v-model.number="gen.cell_from" type="number" min="1" max="99" class="input" /></div>
            <div><label class="label">Ёмкость ячейки (комплектов)</label><input v-model.number="gen.capacity" type="number" min="1" max="10" class="input" /></div>
        </div>
        <p class="mt-3 text-sm text-ink-500">Будут созданы адреса {{ location.code }}_{{ String(gen.row_from).padStart(2, '0') }}_{{ String(gen.cell_from).padStart(2, '0') }} … {{ location.code }}_{{ String(gen.row_to).padStart(2, '0') }}_{{ String(gen.cell_from + gen.cells - 1).padStart(2, '0') }}. Ярусы нумеруйте сквозь (ярус 1: 01–20, ярус 2: 21–40).</p>
        <div class="error" v-if="gen.errors.domain">{{ gen.errors.domain }}</div>
        <button class="btn-primary w-full mt-4" :disabled="gen.processing" @click="gen.post(`/locations/${location.id}/generate`, { onSuccess: () => (showGen = false) })">Создать</button>
    </Modal>
    <Modal :show="!!cell" :title="`Ячейка ${cell?.address}`" @close="cell = null">
        <div v-if="cell?.sets.length" class="mb-3 text-sm">Лежит: <Link v-for="s in cell.sets" :key="s.qr_code" :href="`/contracts/${s.contract_id}`" class="text-blue-600 font-semibold tabular mr-2">{{ s.qr_code }}</Link></div>
        <label class="label">Ёмкость (комплектов)</label><input v-model.number="cellForm.capacity" type="number" min="1" max="10" class="input" />
        <label class="mt-3 flex gap-2 text-sm"><input type="checkbox" v-model="cellForm.is_blocked" class="accent-blue-600" /> Заблокирована (сломана / занята под другое)</label>
        <button class="btn-primary w-full mt-4" @click="cellForm.put(`/cells/${cell.id}`, { preserveScroll: true, onSuccess: () => (cell = null) })">Сохранить</button>
    </Modal>
    <Modal :show="showEdit" title="Локация" @close="showEdit = false">
        <div class="grid gap-3">
            <div><label class="label">Код</label><input v-model="edit.code" class="input tabular" /><div class="error" v-if="edit.errors.code">{{ edit.errors.code }}</div></div>
            <div><label class="label">Название</label><input v-model="edit.name" class="input" /></div>
            <div><label class="label">Адрес</label><input v-model="edit.address" class="input" /></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" v-model="edit.is_active" class="accent-blue-600" /> Действует</label>
            <button class="btn-primary" @click="edit.put(`/locations/${location.id}`, { onSuccess: () => (showEdit = false) })">Сохранить</button>
        </div>
    </Modal>
</template>

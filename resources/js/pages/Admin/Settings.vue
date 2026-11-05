<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import LdapSettings from '../../Components/LdapSettings.vue';
import { dt } from '../../lib/format';
import { ask } from '../../lib/confirm';

const props = defineProps({ cars: Object, scheduler_last_run_at: String, ldap: Object });
const form = useForm({ api_url: props.cars.api_url, api_key: '', remove_key: false });
const busy = ref('');

function save() {
    form.put('/admin/settings/cars', { preserveScroll: true, onSuccess: () => form.reset('api_key', 'remove_key') });
}
async function removeKey() {
    if (!(await ask('Синхронизация справочника марок и моделей остановится.', { title: 'Удалить ключ API?', ok: 'Удалить', danger: true }))) return;
    form.transform((d) => ({ ...d, api_key: '', remove_key: true })).put('/admin/settings/cars', { preserveScroll: true, onFinish: () => form.transform((d) => d) });
}
function action(name, data = {}) {
    busy.value = name;
    router.post(`/admin/settings/cars/${name}`, data, { preserveScroll: true, onFinish: () => (busy.value = '') });
}

const STATUS = { updated: ['обновлён', 'badge-green'], not_modified: ['без изменений', 'badge-green'], error: ['ошибка', 'badge-red'], skipped: ['пропущена', 'badge-gray'] };
const last = computed(() => props.cars.last_sync);
const schedulerMinutes = computed(() => (props.scheduler_last_run_at ? Math.round((Date.now() - new Date(props.scheduler_last_run_at)) / 60000) : null));
const schedulerOk = computed(() => schedulerMinutes.value !== null && schedulerMinutes.value <= 5);
</script>

<template>
    <Head title="Настройки" />
    <PageHeader eyebrow="Администрирование" title="Настройки платформы" />

    <section class="card p-5 sm:p-6">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex-1 min-w-[16rem]">
                <h2 class="text-lg">Справочник марок и моделей авто</h2>
                <p class="text-sm text-ink-500 mt-1">Загружается по API системы записи (zapis.diskimira.com) и используется в селектах «Марка» и «Модель»
                    при приёме на хранение и в гараже клиента. Обновление — {{ cars.schedule }} через планировщик; без изменений в справочнике запрос почти ничего не стоит.</p>
            </div>
            <div class="text-right">
                <div class="text-3xl font-black tabular">{{ cars.makes }}</div><div class="text-sm text-ink-500">марок</div>
            </div>
            <div class="text-right">
                <div class="text-3xl font-black tabular">{{ cars.models }}</div><div class="text-sm text-ink-500">моделей</div>
            </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><label class="label">Адрес API</label>
                <input v-model="form.api_url" class="input" placeholder="https://zapis.diskimira.com/api/v1/vehicles" />
                <div class="error" v-if="form.errors.api_url">{{ form.errors.api_url }}</div></div>
            <div class="sm:col-span-2"><label class="label">Ключ API</label>
                <input v-model="form.api_key" type="password" autocomplete="new-password" class="input"
                    :placeholder="cars.key_set ? `Сохранён: ${cars.key_mask} — введите новый, чтобы заменить` : 'tb_… (создаётся в системе записи: Настройки → API-ключи)'" />
                <div class="text-xs text-ink-500 mt-1">
                    <template v-if="cars.key_from_env">Сейчас используется ключ из .env (CAR_CATALOG_API_KEY). Ключ, сохранённый здесь, будет главнее.</template>
                    <template v-else>Ключ хранится в базе в зашифрованном виде и не показывается целиком; в браузер он не передаётся.</template>
                </div>
                <div class="error" v-if="form.errors.api_key">{{ form.errors.api_key }}</div></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary" :disabled="form.processing" @click="save">Сохранить</button>
            <button class="btn-secondary" :disabled="!cars.key_set || !!busy" @click="action('test')">{{ busy === 'test' ? 'Проверяем…' : 'Проверить подключение' }}</button>
            <button class="btn-secondary" :disabled="!cars.key_set || !!busy" @click="action('sync')">{{ busy === 'sync' ? 'Загружаем…' : 'Синхронизировать сейчас' }}</button>
            <button class="btn-ghost" :disabled="!cars.key_set || !!busy" title="Загрузить весь справочник, не сверяя версию" @click="action('sync', { force: true })">Полная загрузка</button>
            <button v-if="cars.key_set && !cars.key_from_env" class="btn-ghost text-red-600 ml-auto" @click="removeKey">Удалить ключ</button>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3 border-t border-line pt-5">
            <div><div class="text-sm text-ink-500">Последняя синхронизация</div>
                <div v-if="last" class="font-semibold tabular">{{ dt(last.at) }} <span class="badge ml-1" :class="STATUS[last.status]?.[1]">{{ STATUS[last.status]?.[0] || last.status }}</span></div>
                <div v-else class="font-semibold">ещё не было</div>
                <div v-if="last" class="text-sm mt-1" :class="last.status === 'error' ? 'text-red-600' : 'text-ink-500'">{{ last.message }}</div></div>
            <div><div class="text-sm text-ink-500">Последняя успешная</div>
                <div class="font-semibold tabular">{{ cars.last_success_at ? dt(cars.last_success_at) : '—' }}</div>
                <div v-if="cars.version" class="text-xs text-ink-500 mt-1 break-all">версия {{ cars.version }}</div></div>
            <div><div class="text-sm text-ink-500">Планировщик (cron)</div>
                <div class="font-semibold" :class="schedulerOk ? 'text-green-700' : 'text-red-600'">
                    {{ schedulerOk ? 'работает' : schedulerMinutes === null ? 'не запускался' : `не запускался ${schedulerMinutes} мин` }}</div>
                <div v-if="!schedulerOk" class="text-xs text-ink-500 mt-1">Без cron справочник не обновляется автоматически. Задача в ispmanager: <code>artisan schedule:run</code> каждую минуту (docs/INSTALL.md, раздел 9).</div>
                <div v-else class="text-xs text-ink-500 mt-1">последний запуск {{ dt(scheduler_last_run_at) }}</div></div>
        </div>
    </section>

    <LdapSettings :ldap="ldap" />
</template>

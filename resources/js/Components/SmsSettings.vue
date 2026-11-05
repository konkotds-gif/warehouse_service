<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ask } from '../lib/confirm';

// «Настройки → SMS (SMSC)»: подключение к smsc.ru и подтверждение договоров кодом из SMS.
const props = defineProps({ sms: Object });

const form = useForm({
    auth: props.sms.auth,
    apikey: '',
    remove_apikey: false,
    login: props.sms.login || '',
    password: '',
    remove_password: false,
    sender: props.sms.sender || '',
    confirm_contracts: props.sms.confirm_contracts,
    confirm_text: props.sms.confirm_text,
    confirm_close: props.sms.confirm_close,
    close_text: props.sms.close_text,
});
const testPhone = ref('');
const testing = ref(false);

function save() {
    form.put('/admin/settings/sms', { preserveScroll: true, onSuccess: () => form.reset('password', 'remove_password', 'apikey', 'remove_apikey') });
}
async function removeApikey() {
    if (!(await ask('Если выбрана авторизация по API-ключу, SMS перестанут отправляться.', { title: 'Удалить API-ключ SMSC?', ok: 'Удалить', danger: true }))) return;
    form.transform((d) => ({ ...d, apikey: '', remove_apikey: true })).put('/admin/settings/sms', { preserveScroll: true, onFinish: () => form.transform((d) => d) });
}
async function removePassword() {
    if (!(await ask('SMS клиентам и коды подтверждения договоров перестанут отправляться.', { title: 'Удалить пароль SMSC?', ok: 'Удалить', danger: true }))) return;
    form.transform((d) => ({ ...d, password: '', remove_password: true })).put('/admin/settings/sms', { preserveScroll: true, onFinish: () => form.transform((d) => d) });
}
function test() {
    testing.value = true;
    router.post('/admin/settings/sms/test', { phone: testPhone.value }, { preserveScroll: true, onFinish: () => (testing.value = false) });
}
// Пример текста с подставленными значениями и длина (кириллица: 70 символов в одном SMS)
const preview = computed(() => form.confirm_text.replaceAll('{code}', '4821').replaceAll('{number}', '000123'));
const closePreview = computed(() => form.close_text.replaceAll('{code}', '4821').replaceAll('{number}', '000123').replaceAll('{sum}', ', доплата за хранение 210 руб.'));
const closeParts = computed(() => (closePreview.value.length <= 70 ? 1 : Math.ceil(closePreview.value.length / 67)));
const parts = computed(() => (preview.value.length <= 70 ? 1 : Math.ceil(preview.value.length / 67)));
</script>

<template>
    <section class="card p-5 sm:p-6 mt-6">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex-1 min-w-[16rem]">
                <h2 class="text-lg">SMS-уведомления (SMSC)</h2>
                <p class="text-sm text-ink-500 mt-1">Отправка SMS клиентам через <a href="https://smsc.ru" target="_blank" class="text-blue-600">smsc.ru</a>:
                    код подтверждения при оформлении договора и сообщение «колёса готовы к выдаче».</p>
            </div>
            <span class="badge" :class="sms.configured ? 'badge-green' : 'badge-gray'">{{ sms.configured ? 'подключено' : 'не подключено' }}</span>
        </div>

        <div class="mt-5"><label class="label">Авторизация в SMSC</label>
            <div class="seg">
                <button type="button" :class="{ on: form.auth === 'password' }" @click="form.auth = 'password'">Логин и пароль</button>
                <button type="button" :class="{ on: form.auth === 'apikey' }" @click="form.auth = 'apikey'">API-ключ</button>
            </div>
            <div class="error" v-if="form.errors.auth">{{ form.errors.auth }}</div></div>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <template v-if="form.auth === 'password'">
                <div><label class="label">Логин SMSC</label>
                    <input v-model="form.login" class="input" autocomplete="off" />
                    <div class="error" v-if="form.errors.login">{{ form.errors.login }}</div></div>
                <div><label class="label">Пароль SMSC</label>
                    <input v-model="form.password" type="password" autocomplete="new-password" class="input"
                        :placeholder="sms.password_set ? 'Сохранён — введите новый, чтобы заменить' : 'пароль от кабинета или API-пароль'" />
                    <div class="text-xs text-ink-500 mt-1">
                        <template v-if="sms.from_env">Сейчас используется пароль из .env (SMSC_PASSWORD).</template>
                        <template v-else>Хранится в базе в зашифрованном виде, в браузер не передаётся.</template>
                    </div>
                    <div class="error" v-if="form.errors.password">{{ form.errors.password }}</div></div>
            </template>
            <div v-else class="sm:col-span-2"><label class="label">API-ключ SMSC</label>
                <input v-model="form.apikey" type="password" autocomplete="new-password" class="input"
                    :placeholder="sms.apikey_set ? `Сохранён: ${sms.apikey_mask} — введите новый, чтобы заменить` : 'создаётся в кабинете SMSC: Настройки → Пароли (smsc.ru/passwords)'" />
                <div class="text-xs text-ink-500 mt-1">
                    <template v-if="sms.apikey_from_env">Сейчас используется ключ из .env (SMSC_API_KEY).</template>
                    <template v-else>Ключ передаётся вместо логина и пароля. Хранится в базе в зашифрованном виде, в браузер не передаётся.</template>
                </div>
                <div class="error" v-if="form.errors.apikey">{{ form.errors.apikey }}</div></div>
            <div><label class="label">Имя отправителя</label>
                <input v-model="form.sender" class="input" maxlength="15" placeholder="как зарегистрировано в SMSC" />
                <div class="text-xs text-ink-500 mt-1">Пусто — отправитель по умолчанию из кабинета SMSC.</div>
                <div class="error" v-if="form.errors.sender">{{ form.errors.sender }}</div></div>
        </div>

        <div class="mt-5 border-t border-line pt-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="form.confirm_contracts" type="checkbox" class="mt-1 h-5 w-5" />
                <span><span class="font-semibold">Подтверждать новый договор кодом из SMS</span>
                    <span class="block text-sm text-ink-500">При оформлении договора клиенту уходит код из 4 цифр, сотрудник вводит его в карточке договора.
                        Код действует {{ sms.ttl_minutes }} мин., {{ sms.max_attempts }} попыток ввода, повторная отправка — не чаще раза в минуту.</span></span>
            </label>
            <div class="mt-4"><label class="label">Текст SMS с кодом</label>
                <input v-model="form.confirm_text" class="input" maxlength="300" />
                <div class="text-xs text-ink-500 mt-1">{code} — код, {number} — номер договора. Пример: «{{ preview }}» · {{ preview.length }} симв., {{ parts }} SMS</div>
                <div class="error" v-if="form.errors.confirm_text">{{ form.errors.confirm_text }}</div>
                <button v-if="form.confirm_text !== sms.default_text" type="button" class="btn-link text-sm mt-1" @click="form.confirm_text = sms.default_text">Вернуть текст по умолчанию</button></div>

            <label class="flex items-start gap-3 cursor-pointer mt-5">
                <input v-model="form.confirm_close" type="checkbox" class="mt-1 h-5 w-5" />
                <span><span class="font-semibold">Закрывать договор кодом из SMS</span>
                    <span class="block text-sm text-ink-500">При выдаче последнего комплекта («Закрыть договор») клиент подтверждает закрытие кодом.
                        В SMS указывается доплата за хранение после срока. Руководитель может закрыть без кода, указав причину.</span></span>
            </label>
            <div class="mt-4"><label class="label">Текст SMS для закрытия</label>
                <input v-model="form.close_text" class="input" maxlength="300" />
                <div class="text-xs text-ink-500 mt-1">{code} — код, {number} — номер договора, {sum} — «, доплата за хранение N руб.» (пусто, если доплаты нет). Пример: «{{ closePreview }}» · {{ closePreview.length }} симв., {{ closeParts }} SMS</div>
                <div class="error" v-if="form.errors.close_text">{{ form.errors.close_text }}</div>
                <button v-if="form.close_text !== sms.default_close_text" type="button" class="btn-link text-sm mt-1" @click="form.close_text = sms.default_close_text">Вернуть текст по умолчанию</button></div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary" :disabled="form.processing" @click="save">Сохранить</button>
            <button v-if="form.auth === 'password' && sms.password_set && !sms.from_env" class="btn-ghost text-red-600 ml-auto" @click="removePassword">Удалить пароль</button>
            <button v-if="form.auth === 'apikey' && sms.apikey_set && !sms.apikey_from_env" class="btn-ghost text-red-600 ml-auto" @click="removeApikey">Удалить ключ</button>
        </div>

        <div class="mt-6 border-t border-line pt-5 grid gap-3 sm:grid-cols-[1fr_auto] items-end">
            <div><label class="label">Проверка подключения</label>
                <input v-model="testPhone" class="input tabular" placeholder="Телефон для тестового SMS (необязательно)" />
                <div class="text-xs text-ink-500 mt-1">Без телефона — только запрос баланса. Проверяется сохранённая настройка.</div></div>
            <button class="btn-secondary" :disabled="!sms.configured || testing" @click="test">{{ testing ? 'Проверяем…' : testPhone ? 'Проверить и отправить SMS' : 'Проверить баланс' }}</button>
        </div>
    </section>
</template>

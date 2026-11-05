<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { getJson, postJson } from '../lib/http';
import { dt } from '../lib/format';

// «Настройки → Вход через Active Directory»: подключение, проверка, сопоставление групп AD и ролей.
const props = defineProps({ ldap: Object });

const form = useForm({ ...props.ldap.config, bind_password: '', remove_password: false });
watch(() => form.encryption, (enc, old) => {
    // Стандартные порты: LDAPS — 636, StartTLS и без шифрования — 389
    if (old && ((enc === 'ldaps' && form.port === 389) || (enc !== 'ldaps' && form.port === 636))) form.port = enc === 'ldaps' ? 636 : 389;
});

function save() {
    form.put('/admin/settings/ldap', { preserveScroll: true, onSuccess: () => form.reset('bind_password', 'remove_password') });
}
function removePassword() {
    if (!confirm('Удалить пароль сервисной учётной записи? Вход через AD перестанет работать.')) return;
    form.transform((d) => ({ ...d, bind_password: '', remove_password: true, enabled: false }))
        .put('/admin/settings/ldap', { preserveScroll: true, onFinish: () => form.transform((d) => d) });
}

// Проверка подключения — с тем, что введено в форме (сохранять не обязательно)
const testing = ref('');
const testResult = ref(null);
async function test() {
    testing.value = 'conn';
    testResult.value = null;
    try {
        testResult.value = await postJson('/admin/settings/ldap/test', form.data());
    } catch (e) {
        testResult.value = { ok: false, message: e.message };
    } finally {
        testing.value = '';
    }
}

const probe = ref({ login: '', password: '' });
const probeResult = ref(null);
async function testUser() {
    testing.value = 'user';
    probeResult.value = null;
    try {
        probeResult.value = await postJson('/admin/settings/ldap/test-user', { ...form.data(), ...probe.value });
    } catch (e) {
        probeResult.value = { ok: false, message: e.message };
    } finally {
        testing.value = '';
        probe.value.password = '';
    }
}

// Сопоставление групп
const mapping = useForm({ id: null, group_dn: '', group_name: '', role: 'seller', location_id: null });
const groupQuery = ref('');
const groups = ref(null);
const groupError = ref('');
const searching = ref(false);
async function findGroups() {
    searching.value = true;
    groupError.value = '';
    try {
        groups.value = (await getJson('/admin/settings/ldap/groups?q=' + encodeURIComponent(groupQuery.value))).data;
    } catch (e) {
        groups.value = null;
        groupError.value = e.message;
    } finally {
        searching.value = false;
    }
}
function pickGroup(g) {
    mapping.group_dn = g.dn;
    mapping.group_name = g.name;
    groups.value = null;
}
function editMapping(m) {
    Object.assign(mapping, { id: m.id, group_dn: m.group_dn, group_name: m.group_name, role: m.role, location_id: m.location_id });
    mapping.clearErrors();
}
function resetMapping() {
    Object.assign(mapping, { id: null, group_dn: '', group_name: '', role: 'seller', location_id: null });
    mapping.clearErrors();
}
function saveMapping() {
    const opts = { preserveScroll: true, onSuccess: resetMapping };
    mapping.id ? mapping.put(`/admin/settings/ldap/mappings/${mapping.id}`, opts) : mapping.post('/admin/settings/ldap/mappings', opts);
}
function deleteMapping(m) {
    if (!confirm(`Удалить сопоставление для группы «${m.group_name}»? Её участники потеряют эту роль при следующем входе или сверке.`)) return;
    router.delete(`/admin/settings/ldap/mappings/${m.id}`, { preserveScroll: true });
}

const syncing = ref(false);
function sync() {
    syncing.value = true;
    router.post('/admin/settings/ldap/sync', {}, { preserveScroll: true, onFinish: () => (syncing.value = false) });
}

const status = computed(() => {
    if (!props.ldap.config.enabled) return ['выключен', 'badge-gray'];
    if (!props.ldap.extension) return ['нет расширения ldap', 'badge-red'];
    if (!props.ldap.mappings.length) return ['включён, но нет групп', 'badge-amber'];
    return ['включён', 'badge-green'];
});
const insecure = computed(() => form.encryption === 'none');
</script>

<template>
    <section class="card p-5 sm:p-6 mt-6">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex-1 min-w-[16rem]">
                <h2 class="text-lg">Вход через Active Directory <span class="badge ml-1 align-middle" :class="status[1]">{{ status[0] }}</span></h2>
                <p class="text-sm text-ink-500 mt-1">Сотрудники входят доменным логином и паролем (как в Windows: <code>ivanov</code>, <code>DOMAIN\ivanov</code>
                    или <code>ivanov@домен</code>). Роль назначается по группам AD при каждом входе. Пользователи, созданные в платформе вручную
                    (например, <code>admin</code>), по-прежнему входят своим паролем — это запасной вход, если контроллер домена недоступен.</p>
            </div>
            <div class="text-right">
                <div class="text-3xl font-black tabular">{{ ldap.users.total }}</div><div class="text-sm text-ink-500">доменных<br />пользователей</div>
            </div>
        </div>

        <div v-if="!ldap.extension" class="mt-4 rounded-xl bg-red-50 text-red-700 p-3 text-sm">
            На сервере не установлено PHP-расширение <b>ldap</b> для PHP 8.4. Включите его в ispmanager (Настройки → PHP → 8.4 → Расширения → ldap)
            или <code>apt install php8.4-ldap</code> и перезапустите PHP-FPM — см. docs/INSTALL.md, раздел «Вход через Active Directory».
        </div>

        <h3 class="mt-6 font-bold">Подключение</h3>
        <div class="mt-3 grid gap-4 sm:grid-cols-6">
            <div class="sm:col-span-4"><label class="label">Контроллеры домена</label>
                <input v-model="form.hosts" class="input" placeholder="dc1.corp.local, dc2.corp.local" />
                <div class="text-xs text-ink-500 mt-1">Имя или IP; несколько — через запятую, используется первый доступный.</div>
                <div class="error" v-if="form.errors.hosts">{{ form.errors.hosts }}</div></div>
            <div class="sm:col-span-2"><label class="label">Шифрование</label>
                <select v-model="form.encryption" class="input">
                    <option value="ldaps">LDAPS (рекомендуется)</option>
                    <option value="starttls">StartTLS</option>
                    <option value="none">Без шифрования</option>
                </select></div>
            <div class="sm:col-span-1"><label class="label">Порт</label>
                <input v-model.number="form.port" type="number" class="input tabular" />
                <div class="error" v-if="form.errors.port">{{ form.errors.port }}</div></div>
            <div class="sm:col-span-1"><label class="label">Таймаут, с</label>
                <input v-model.number="form.timeout" type="number" min="1" max="30" class="input tabular" /></div>
            <div class="sm:col-span-4"><label class="label">Base DN (где искать пользователей и группы)</label>
                <input v-model="form.base_dn" class="input" placeholder="DC=corp,DC=local" />
                <div class="error" v-if="form.errors.base_dn">{{ form.errors.base_dn }}</div></div>
            <div class="sm:col-span-3"><label class="label">Сервисная учётная запись</label>
                <input v-model="form.bind_user" class="input" autocomplete="off" placeholder="svc-wh@corp.local" />
                <div class="text-xs text-ink-500 mt-1">Обычная доменная учётка без прав администратора, только для чтения каталога. UPN или DN.</div>
                <div class="error" v-if="form.errors.bind_user">{{ form.errors.bind_user }}</div></div>
            <div class="sm:col-span-3"><label class="label">Пароль сервисной учётной записи</label>
                <input v-model="form.bind_password" type="password" autocomplete="new-password" class="input"
                    :placeholder="ldap.password_set ? 'Сохранён — введите новый, чтобы заменить' : ''" />
                <div class="text-xs text-ink-500 mt-1">Хранится в базе в зашифрованном виде, в браузер не передаётся.
                    <button v-if="ldap.password_set" type="button" class="text-red-600 underline" @click="removePassword">Удалить пароль</button></div>
                <div class="error" v-if="form.errors.bind_password">{{ form.errors.bind_password }}</div></div>
            <div class="sm:col-span-3"><label class="label">Домен (для подсказки на странице входа)</label>
                <input v-model="form.domain" class="input" placeholder="CORP" /></div>
            <div class="sm:col-span-3 flex items-end">
                <label class="flex gap-2 text-sm pb-3"><input type="checkbox" v-model="form.verify_cert" class="accent-blue-600" :disabled="insecure" />
                    Проверять сертификат контроллера домена</label></div>
            <details class="sm:col-span-6" :open="!!form.ca_cert">
                <summary class="cursor-pointer text-sm font-semibold">Корневой сертификат CA (если сертификат DC выдан внутренним центром сертификации)</summary>
                <textarea v-model="form.ca_cert" rows="5" class="input mt-2 font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----&#10;…&#10;-----END CERTIFICATE-----"></textarea>
                <div class="text-xs text-ink-500 mt-1">Экспорт на DC: certmgr → Доверенные корневые ЦС → сертификат вашего CA → «Экспорт» в формате Base-64 (.cer), вставьте содержимое файла.</div>
                <div class="error" v-if="form.errors.ca_cert">{{ form.errors.ca_cert }}</div>
            </details>
            <div v-if="insecure" class="sm:col-span-6 rounded-xl bg-amber-500/15 text-amber-800 p-3 text-sm">
                Без шифрования пароли сотрудников передаются по сети открытым текстом, а контроллер домена с включённым требованием подписи LDAP
                отклонит такой вход. Используйте только для проверки.</div>
            <label class="sm:col-span-6 flex gap-2 font-semibold"><input type="checkbox" v-model="form.enabled" class="accent-blue-600" /> Разрешить вход через AD</label>
            <div class="sm:col-span-6 error" v-if="form.errors.enabled">{{ form.errors.enabled }}</div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary" :disabled="form.processing" @click="save">Сохранить</button>
            <button class="btn-secondary" :disabled="!!testing" @click="test">{{ testing === 'conn' ? 'Проверяем…' : 'Проверить подключение' }}</button>
        </div>
        <div v-if="testResult" class="mt-3 rounded-xl p-3 text-sm" :class="testResult.ok ? 'bg-green-500/15 text-green-800' : 'bg-red-50 text-red-700'">{{ testResult.message }}</div>

        <h3 class="mt-8 font-bold">Проверка пользователя</h3>
        <p class="text-sm text-ink-500 mt-1">Покажет, найдена ли учётка, в каких она группах (с учётом вложенных) и какую роль получит. С паролем — проверит и сам вход.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] items-end">
            <div><label class="label">Логин</label><input v-model="probe.login" class="input" autocomplete="off" placeholder="ivanov" /></div>
            <div><label class="label">Пароль (необязательно)</label><input v-model="probe.password" type="password" class="input" autocomplete="new-password" /></div>
            <button class="btn-secondary" :disabled="!!testing || !probe.login" @click="testUser">{{ testing === 'user' ? 'Проверяем…' : 'Проверить' }}</button>
        </div>
        <div v-if="probeResult" class="mt-3 rounded-xl border border-line p-4 text-sm">
            <div :class="probeResult.ok ? 'text-green-700' : 'text-red-600'" class="font-semibold">{{ probeResult.message }}</div>
            <template v-if="probeResult.user">
                <div class="mt-2"><b>{{ probeResult.user.name }}</b> ({{ probeResult.user.login }}<template v-if="probeResult.user.email">, {{ probeResult.user.email }}</template>)</div>
                <div class="text-xs text-ink-500 break-all">{{ probeResult.user.dn }}</div>
                <div class="mt-2">Роль в платформе: <b>{{ probeResult.role || 'нет доступа' }}</b><template v-if="probeResult.location">, локация {{ probeResult.location }}</template></div>
                <div class="mt-2 text-ink-500">Группы ({{ probeResult.groups.length }}):</div>
                <ul class="mt-1 grid gap-1">
                    <li v-for="g in probeResult.groups" :key="g.dn" :title="g.dn">{{ g.name }} <span v-if="g.role" class="badge badge-blue ml-1">{{ g.role }}</span></li>
                </ul>
            </template>
        </div>

        <h3 class="mt-8 font-bold">Группы AD и роли</h3>
        <p class="text-sm text-ink-500 mt-1">Участник группы (в том числе через вложенную группу) получает её роль. Если групп несколько — старшая роль:
            Администратор → Руководитель магазина → Кладовщик → Продавец → Водитель. Не входит ни в одну — вход запрещён.
            Магазин/склад необязателен: если указан, он назначается пользователю при входе.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="table">
                <thead><tr><th>Группа AD</th><th>Роль</th><th>Локация</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="m in ldap.mappings" :key="m.id">
                        <td><div class="font-semibold">{{ m.group_name }}</div><div class="text-xs text-ink-500 break-all">{{ m.group_dn }}</div></td>
                        <td>{{ m.role_label }}</td><td>{{ m.location || '—' }}</td>
                        <td class="whitespace-nowrap text-right">
                            <button class="btn-ghost !min-h-8" @click="editMapping(m)">Изменить</button>
                            <button class="btn-ghost !min-h-8 text-red-600" @click="deleteMapping(m)">Удалить</button></td>
                    </tr>
                    <tr v-if="!ldap.mappings.length"><td colspan="4" class="text-ink-500">Сопоставлений нет — через AD пока никто не сможет войти.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 rounded-xl border border-line p-4">
            <div class="font-semibold">{{ mapping.id ? 'Изменить сопоставление' : 'Добавить сопоставление' }}</div>
            <div class="mt-3 flex gap-2">
                <input v-model="groupQuery" class="input" placeholder="Поиск группы в AD: часть названия" @keydown.enter.prevent="findGroups" />
                <button class="btn-secondary" :disabled="searching" @click="findGroups">{{ searching ? 'Ищем…' : 'Найти' }}</button>
            </div>
            <div v-if="groupError" class="error">{{ groupError }}</div>
            <div v-if="groups" class="mt-2 max-h-64 overflow-y-auto rounded-xl border border-line">
                <button v-for="g in groups" :key="g.dn" type="button" class="block w-full text-left px-3 py-2 hover:bg-slate-50 border-b border-line last:border-0" @click="pickGroup(g)">
                    <div class="font-semibold">{{ g.name }}</div>
                    <div class="text-xs text-ink-500 break-all">{{ g.description ? g.description + ' · ' : '' }}{{ g.dn }}</div>
                </button>
                <div v-if="!groups.length" class="px-3 py-2 text-sm text-ink-500">Ничего не найдено (показываются первые 50 групп).</div>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-3"><label class="label">Группа</label>
                    <div v-if="mapping.group_dn" class="input !h-auto py-2"><b>{{ mapping.group_name }}</b><div class="text-xs text-ink-500 break-all">{{ mapping.group_dn }}</div></div>
                    <div v-else class="text-sm text-ink-500">Найдите и выберите группу выше.</div>
                    <div class="error" v-if="mapping.errors.group_dn">{{ mapping.errors.group_dn }}</div></div>
                <div><label class="label">Роль</label>
                    <select v-model="mapping.role" class="input"><option v-for="r in ldap.roles" :key="r.value" :value="r.value">{{ r.label }}</option></select></div>
                <div><label class="label">Локация</label>
                    <select v-model="mapping.location_id" class="input"><option :value="null">— не менять —</option>
                        <option v-for="l in ldap.locations" :key="l.id" :value="l.id">{{ l.code }} · {{ l.name }}</option></select></div>
                <div class="flex items-end gap-2">
                    <button class="btn-primary" :disabled="mapping.processing || !mapping.group_dn" @click="saveMapping">{{ mapping.id ? 'Сохранить' : 'Добавить' }}</button>
                    <button v-if="mapping.id || mapping.group_dn" class="btn-ghost" @click="resetMapping">Отмена</button>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3 border-t border-line pt-5">
            <div><div class="text-sm text-ink-500">Сверка с AD (каждый час)</div>
                <div v-if="ldap.last_sync" class="font-semibold tabular">{{ dt(ldap.last_sync.at) }}
                    <span class="badge ml-1" :class="ldap.last_sync.status === 'ok' ? 'badge-green' : 'badge-red'">{{ ldap.last_sync.status === 'ok' ? 'выполнена' : 'ошибка' }}</span></div>
                <div v-else class="font-semibold">ещё не было</div>
                <div v-if="ldap.last_sync" class="text-sm mt-1" :class="ldap.last_sync.status === 'ok' ? 'text-ink-500' : 'text-red-600'">{{ ldap.last_sync.message }}</div></div>
            <div><div class="text-sm text-ink-500">Доступ закрыт со стороны AD</div>
                <div class="font-semibold tabular">{{ ldap.users.denied }}</div>
                <div class="text-xs text-ink-500 mt-1">Отключены в домене или убраны из групп; их сеансы завершены. Вернётся сам, если вернуть в группу.</div></div>
            <div class="flex items-start">
                <button class="btn-secondary" :disabled="syncing || !ldap.config.enabled" @click="sync">{{ syncing ? 'Сверяем…' : 'Сверить сейчас' }}</button></div>
        </div>
    </section>
</template>

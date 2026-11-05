<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Logo from '../../Components/Logo.vue';
import Icon from '../../Components/Icon.vue';

defineProps({ ldap: Object });

const form = useForm({ login: '', password: '', remember: true });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Вход" />
    <div class="hero-bg min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-5xl grid gap-10 lg:grid-cols-2 items-center">
            <div class="text-white hidden lg:block">
                <Logo light />
                <div class="eyebrow mt-10 !text-red-600">Внутренний сервис сети</div>
                <h1 class="mt-3 text-5xl xl:text-6xl font-black leading-tight">Хранение колёс без путаницы</h1>
                <p class="mt-5 max-w-md text-lg text-white/70">Приём, адреса XX_YY_ZZ, перемещения и выдача — с полной историей по каждому комплекту.</p>
            </div>
            <form class="card p-8 w-full max-w-md mx-auto" @submit.prevent="submit">
                <div class="lg:hidden mb-6"><Logo /></div>
                <h2 class="text-2xl">Вход для сотрудников</h2>
                <p v-if="ldap" class="mt-1 text-ink-500 text-sm">Войдите доменной учётной записью — тем же логином и паролем, что и в Windows<template v-if="ldap.domain"> (домен {{ ldap.domain }})</template>.</p>
                <p v-else class="mt-1 text-ink-500 text-sm">Используйте логин и пароль, выданные администратором.</p>
                <div class="mt-6">
                    <label class="label" for="login">Логин</label>
                    <input id="login" v-model="form.login" class="input" autocomplete="username" autofocus autocapitalize="none" :placeholder="ldap ? (ldap.domain ? `ivanov или ${ldap.domain}\\ivanov` : 'ivanov') : ''" />
                    <div v-if="form.errors.login" class="error">{{ form.errors.login }}</div>
                </div>
                <div class="mt-4">
                    <label class="label" for="password">Пароль</label>
                    <input id="password" v-model="form.password" type="password" class="input" autocomplete="current-password" />
                    <div v-if="form.errors.password" class="error">{{ form.errors.password }}</div>
                </div>
                <label class="mt-4 flex items-center gap-2 text-sm text-ink-500">
                    <input v-model="form.remember" type="checkbox" class="h-4 w-4 accent-blue-600" /> Запомнить на этом устройстве
                </label>
                <button class="btn-primary btn-lg mt-6 w-full" :disabled="form.processing">Войти <Icon name="arrow" :size="18" /></button>
            </form>
        </div>
    </div>
</template>

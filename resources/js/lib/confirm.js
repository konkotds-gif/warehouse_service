import { reactive } from 'vue';

/**
 * Подтверждение действия во всплывающем окне платформы вместо системного confirm() браузера.
 * Использование: if (await ask('Отменить ведомость?', { ok: 'Отменить', danger: true })) { … }
 */
export const dialog = reactive({ open: false, title: '', text: '', ok: 'OK', cancel: 'Отмена', danger: false, resolve: null });

export function ask(text, { title = 'Подтвердите действие', ok = 'OK', cancel = 'Отмена', danger = false } = {}) {
    if (dialog.resolve) dialog.resolve(false);
    return new Promise((resolve) => {
        Object.assign(dialog, { open: true, title, text, ok, cancel, danger, resolve });
    });
}

export function answer(value) {
    const resolve = dialog.resolve;
    Object.assign(dialog, { open: false, resolve: null });
    if (resolve) resolve(value);
}

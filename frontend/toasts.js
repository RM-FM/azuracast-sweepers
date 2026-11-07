import { reactive } from "vue";

const SHORT_MS = 5000;
const PER_CHARACTER_MS = 60;
const LONG_MS = 15000;

/** Toasts shown like AzuraCast's own notifications, top right. */
export const toasts = reactive([]);
let lastId = 0;

/** How long a toast stays: AzuraCast's five seconds, longer for text that takes longer to read. */
export function toastDuration(text) {
    return Math.min(LONG_MS, Math.max(SHORT_MS, text.length * PER_CHARACTER_MS));
}

export function removeToast(id) {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index >= 0) toasts.splice(index, 1);
}

function notify(message, variant) {
    if (!message) return;
    const toast = { id: ++lastId, message, variant };
    toasts.push(toast);
    setTimeout(() => removeToast(toast.id), toastDuration(message));
}

export function notifySuccess(message) {
    notify(message, "success");
}

export function notifyError(message) {
    notify(message, "danger");
}
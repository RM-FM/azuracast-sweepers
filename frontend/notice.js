import { getCurrentInstance, onUnmounted, ref, watch } from "vue";

const SHORT_MS = 5000;
const PER_CHARACTER_MS = 60;
const LONG_MS = 15000;

/** How long a notice stays: long enough to read it, at least five seconds. */
export function noticeDuration(text) {
    return Math.min(LONG_MS, Math.max(SHORT_MS, text.length * PER_CHARACTER_MS));
}

/** A notice that disappears on its own; errors are kept separately and stay. */
export function useNotice() {
    const notice = ref("");
    let timer;
    // Synchronous, so clearing and setting the same text again restarts the timer.
    watch(notice, (text) => {
        clearTimeout(timer);
        if (text) timer = setTimeout(() => { notice.value = ""; }, noticeDuration(text));
    }, { flush: "sync" });
    if (getCurrentInstance()) onUnmounted(() => clearTimeout(timer));
    return notice;
}
<template>
    <header>
        <div>
            <a :href="stationUrl">← AzuraCast</a>
            <h1>Sweepers</h1>
            <p>{{ station.name }}</p>
        </div>
    </header>

    <main>
        <p v-if="error" class="alert danger" role="alert">{{ error }}</p>
        <p v-if="notice" class="alert" role="status">{{ notice }}</p>
        <p v-if="loading">Loading…</p>

        <section v-if="!loading" class="card">
            <h2>Sweeper settings</h2>
            <form @submit.prevent="saveSettings">
                <label class="check"><input v-model="settings.enabled" type="checkbox"> Enable sweepers</label>
                <label>
                    Sweeper playlist
                    <select v-model="settings.playlist_id">
                        <option :value="null">Choose an Advanced playlist</option>
                        <option v-for="playlist in playlists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.name }}
                        </option>
                    </select>
                </label>
                <p>Jingles rotate by oldest actual playback, choosing those that fit the available intro.</p>
                <div class="grid">
                    <label>
                        Maximum start delay (s)
                        <input
                            v-model.number="settings.max_wait"
                            type="number"
                            min="0"
                            max="30"
                            step="0.1"
                            required
                        >
                    </label>
                    <label>
                        Handover margin (s)
                        <input v-model.number="settings.margin" type="number" min="0" max="5" step="0.1" required>
                    </label>
                    <label>
                        Maximum ducking (dB)
                        <input v-model.number="settings.duck_db" type="number" min="-20" max="0" step="0.5" required>
                    </label>
                    <label>
                        Jingle gain
                        <input v-model.number="settings.jingle_gain" type="number" min="0" max="2" step="0.05" required>
                    </label>
                </div>
                <p>Sidechain attack: 150 ms · release: 750 ms. AutoCue supplies jingle start, handover and tail.</p>
                <button class="primary" :disabled="saving">Save settings</button>
                <p>Set Intro End under Music Files → Edit → Custom Fields, or use the intro API.</p>
            </form>
        </section>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";

const props = defineProps({ base: { type: String, required: true }, csrf: { type: String, required: true } });
const station = ref({});
const settings = ref({});
const playlists = ref([]);
const loading = ref(true);
const saving = ref(false);
const notice = ref("");
const error = ref("");
const stationUrl = computed(() => `${props.base.replace(/\/sweepers\/[^/]+$/, "")}/station/${station.value.id ?? ""}`);

async function api(path, data) {
    const response = await fetch(`${props.base}/${path}`, {
        method: data === undefined ? "GET" : "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": props.csrf },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    const result = await response.json().catch(() => {
        throw new Error("The session expired or the server could not complete the request. Reload the page.");
    });
    if (!response.ok) {
        throw new Error(result.message || result.error?.message || "The request failed.");
    }

    return result;
}

async function saveSettings() {
    await save(async () => {
        await api("settings", settings.value);
        notice.value = "Settings saved. Restart broadcasting in AzuraCast to apply them.";
    });
}

async function save(action) {
    saving.value = true;
    error.value = "";
    notice.value = "";
    try {
        await action();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const state = await api("state");
        station.value = state.station;
        settings.value = state.settings;
        playlists.value = state.playlists;
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});
</script>

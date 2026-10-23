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
        <section v-if="!loading" class="layout">
            <aside class="card">
                <h2>Intro cues</h2>
                <form class="search" @submit.prevent="loadMedia">
                    <label for="search">Find audio</label>
                    <input id="search" v-model="search" type="search" placeholder="Artist, title or filename">
                    <button type="submit">Search</button>
                </form>
                <p>Up to 100 matches. Refine your search to find more.</p>
                <ul class="media-list">
                    <li v-for="row in rows" :key="row.id">
                        <button type="button" :class="{ selected: selected?.id === row.id }" @click="selected = row">
                            <strong>{{ row.path }}</strong>
                            <span>{{ formatIntro(row.intro_end) }}</span>
                        </button>
                    </li>
                </ul>
                <p v-if="!rows.length">No matching files.</p>
            </aside>

            <article v-if="selected" class="card">
                <h2>{{ selected.path }}</h2>
                <p>Intro End: {{ formatIntro(selected.intro_end) }} · Original file position</p>
                <Waveform
                    :key="selected.id"
                    ref="waveform"
                    :audio-url="selected.audio_url"
                    :waveform-url="selected.waveform_url"
                    :intro="selected.intro_end"
                />
                <div v-if="selected.intro_end !== null" class="toolbar">
                    <button type="button" @click="waveform?.playAt(selected.intro_end)">Listen at Intro</button>
                    <button type="button" @click="waveform?.playAt(selected.intro_end - 3)">Listen −3 s</button>
                </div>
                <p>Change the cue under Music Files → Custom Fields or through the intro API. Search again to refresh the marker.</p>
            </article>
            <article v-else class="card"><p>Select a file to view its intro cue.</p></article>
        </section>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import Waveform from "./Waveform.vue";

const props = defineProps({ base: { type: String, required: true }, csrf: { type: String, required: true } });
const station = ref({});
const settings = ref({});
const playlists = ref([]);
const rows = ref([]);
const search = ref("");
const selected = ref(null);
const waveform = ref(null);
const loading = ref(true);
const saving = ref(false);
const notice = ref("");
const error = ref("");
const stationUrl = computed(() => `${props.base.replace(/\/sweepers\/[^/]+$/, "")}/station/${station.value.id ?? ""}`);

function formatIntro(value) {
    if (value === null) return "Not measured";
    if (value === 0) return "No intro";
    return `${value.toFixed(2)} s`;
}

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

async function loadMedia() {
    try {
        rows.value = (await api(`media?search=${encodeURIComponent(search.value)}`)).rows;
        selected.value = rows.value.find((row) => row.id === selected.value?.id) ?? null;
    } catch (exception) {
        error.value = exception.message;
    }
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
        await loadMedia();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});
</script>

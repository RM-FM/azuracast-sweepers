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
                <label>
                    Minimum songs between jingles or sweepers
                    <input v-model.number="settings.minimum_songs" type="number" min="0" max="1000" step="1" required>
                </label>
                <p>0 disables spacing. With 1, one song plays without a sweeper between events. The song carrying the previous sweeper does not count.</p>
                <label class="check"><input v-model="settings.count_jingle_mode" type="checkbox"> Count Jingle Mode playlists</label>
                <label>
                    Additional jingle playlists
                    <select v-model="settings.jingle_playlist_ids" multiple size="5">
                        <option v-for="playlist in jinglePlaylists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.name }}{{ playlist.is_jingle ? ' (Jingle Mode)' : '' }}
                        </option>
                    </select>
                </label>
                <p>Hold Ctrl/Cmd to select several. Tracks played from these playlists reset the spacing. The sweeper playlist always counts.</p>
                <label class="check"><input v-model="settings.longest_fit" type="checkbox"> Prefer the longest fitting sweepers</label>
                <label v-if="settings.longest_fit">
                    Intro length tolerance (%)
                    <input v-model.number="settings.length_tolerance" type="number" min="0" max="100" step="1" required>
                </label>
                <p v-if="settings.longest_fit">20% selects bodies covering 80–100% of the available intro, then rotates by oldest playback. If none qualify, use the longest fitting body. A small selection may repeat often.</p>
                <p v-else>Rotate by oldest actual playback among all sweepers that fit.</p>
                <label class="check"><input v-model="limitDelay" type="checkbox"> Limit start delay</label>
                <p>Disabled: time the handover to the intro end. Enabled: if that would exceed the limit, start immediately. 0 always starts immediately.</p>
                <div class="grid">
                    <label v-if="limitDelay">
                        Maximum start delay (s)
                        <input
                            v-model.number="settings.max_wait"
                            type="number"
                            min="0"
                            max="3600"
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
                <p>Edit Intro End in the waveform below, under Music Files → Custom Fields, or through the intro API.</p>
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
                        <button type="button" :class="{ selected: selected?.id === row.id }" @click="select(row)">
                            <strong>{{ row.path }}</strong>
                            <span>{{ formatIntro(row.intro_end) }}</span>
                        </button>
                    </li>
                </ul>
                <p v-if="!rows.length">No matching files.</p>
            </aside>

            <article v-if="selected" class="card">
                <h2>{{ selected.path }}</h2>
                <p>Saved Intro End: {{ formatIntro(selected.intro_end) }} · Original file position</p>
                <Waveform
                    :key="selected.id"
                    ref="waveform"
                    :audio-url="selected.audio_url"
                    :waveform-url="selected.waveform_url"
                    :intro="marker"
                    @position="position = $event"
                    @intro="draft = $event"
                />
                <div class="toolbar">
                    <button type="button" :disabled="!waveform?.ready" @click="draft = Math.round(position * 100) / 100">Set at playhead</button>
                    <button type="button" :disabled="marker === null || !waveform?.ready" @click="waveform?.playAt(marker)">Listen at Intro</button>
                    <button type="button" :disabled="marker === null || !waveform?.ready" @click="waveform?.playAt(marker - 3)">Listen −3 s</button>
                </div>
                <form @submit.prevent="saveIntro">
                    <label>
                        Intro End (s)
                        <input v-model="draft" type="number" min="0" :max="selected.duration" step="0.01">
                    </label>
                    <p>Move the marker, or seek in the waveform and set the cue at the playhead. Blank means unknown; zero means no intro.</p>
                    <div class="toolbar">
                        <button type="button" @click="draft = 0">No intro (0 s)</button>
                        <button type="button" @click="draft = ''">Clear cue</button>
                        <button class="primary" :disabled="saving || !validDraft">Save Intro End</button>
                    </div>
                </form>
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
const jinglePlaylists = ref([]);
const rows = ref([]);
const search = ref("");
const selected = ref(null);
const waveform = ref(null);
const draft = ref("");
const position = ref(0);
const loading = ref(true);
const saving = ref(false);
const notice = ref("");
const error = ref("");
const stationUrl = computed(() => `${props.base.replace(/\/sweepers\/[^/]+$/, "")}/station/${station.value.id ?? ""}`);
const validDraft = computed(() => draft.value === "" || (
    draft.value !== null && draft.value !== undefined
    && Number.isFinite(Number(draft.value))
    && Number(draft.value) >= 0
    && Number(draft.value) <= (selected.value?.duration ?? 0)
));
const limitDelay = computed({
    get: () => settings.value.max_wait !== null && settings.value.max_wait !== undefined,
    set: (enabled) => { settings.value.max_wait = enabled ? 0 : null; },
});
const marker = computed(() => validDraft.value && draft.value !== "" ? Number(draft.value) : null);

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
        const updated = rows.value.find((row) => row.id === selected.value?.id);
        if (updated) select(updated);
        else selected.value = null;
    } catch (exception) {
        error.value = exception.message;
    }
}

function select(row) {
    if (selected.value?.id !== row.id) position.value = 0;
    selected.value = row;
    draft.value = row.intro_end ?? "";
}

async function saveIntro() {
    if (!validDraft.value) return;
    await save(async () => {
        const id = selected.value.id;
        const value = marker.value;
        const result = await api(`intro/${id}`, { intro_end: value });
        rows.value = rows.value.map((row) => row.id === id ? { ...row, intro_end: result.intro_end } : row);
        if (selected.value?.id === id) {
            selected.value = { ...selected.value, intro_end: result.intro_end };
            if (marker.value === value) draft.value = result.intro_end ?? "";
        }
        notice.value = "Intro End saved. Already-prefetched tracks may retain their previous plan.";
    });
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
        jinglePlaylists.value = state.jingle_playlists ?? [];
        await loadMedia();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});
</script>

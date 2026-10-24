<template>
    <main class="sweepers-content">
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
        <p v-if="notice" class="alert alert-info" role="status">{{ notice }}</p>
        <p v-if="loading" class="text-muted">Loading…</p>

        <section v-if="!loading" class="card mb-3" role="region" aria-labelledby="sweepers-settings-heading">
            <div class="card-header text-bg-primary d-flex justify-content-between align-items-center">
                <h2 id="sweepers-settings-heading" class="card-title mb-0">Sweeper settings</h2>
                <a class="btn btn-sm btn-outline-light" :href="stationUrl">Station dashboard</a>
            </div>
            <div class="card-body">
            <p class="text-muted">Configure overlays for {{ station.name || 'your station' }}.</p>
            <form @submit.prevent="saveSettings">
                <label class="form-check mb-3"><input v-model="settings.enabled" class="form-check-input" type="checkbox"> Enable sweepers</label>
                <label class="d-block mb-3">
                    Sweeper playlist
                    <select v-model="settings.playlist_id" class="form-select">
                        <option :value="null">Choose an Advanced playlist</option>
                        <option v-for="playlist in playlists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.name }}
                        </option>
                    </select>
                </label>
                <label class="d-block mb-1">
                    Minimum songs between jingles or sweepers
                    <input v-model.number="settings.minimum_songs" class="form-control" type="number" min="0" max="1000" step="1" required>
                </label>
                <p class="form-text mb-3">0 disables spacing. With 1, one song plays without a sweeper between events. The song carrying the previous sweeper does not count.</p>
                <label class="form-check mb-3"><input v-model="settings.count_jingle_mode" class="form-check-input" type="checkbox"> Count Jingle Mode playlists</label>
                <label class="d-block mb-1">
                    Additional jingle playlists
                    <select v-model="settings.jingle_playlist_ids" class="form-select" multiple size="5">
                        <option v-for="playlist in jinglePlaylists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.name }}{{ playlist.is_jingle ? ' (Jingle Mode)' : '' }}
                        </option>
                    </select>
                </label>
                <p class="form-text mb-3">Hold Ctrl/Cmd to select several. Tracks played from these playlists reset the spacing. The sweeper playlist always counts.</p>
                <label class="form-check mb-3"><input v-model="settings.longest_fit" class="form-check-input" type="checkbox"> Prefer the longest fitting sweepers</label>
                <label v-if="settings.longest_fit" class="d-block mb-1">
                    Intro length tolerance (%)
                    <input v-model.number="settings.length_tolerance" class="form-control" type="number" min="0" max="100" step="1" required>
                </label>
                <p v-if="settings.longest_fit" class="form-text mb-3">20% selects bodies covering 80–100% of the available intro, then rotates by oldest playback. If none qualify, use the longest fitting body. A small selection may repeat often.</p>
                <p v-else class="form-text mb-3">Rotate by oldest actual playback among all sweepers that fit.</p>
                <label class="form-check mb-1"><input v-model="limitDelay" class="form-check-input" type="checkbox"> Limit start delay</label>
                <p class="form-text mb-3">Disabled: time the handover to the intro end. Enabled: if that would exceed the limit, start immediately. 0 always starts immediately.</p>
                <div class="row g-3 mb-3">
                    <label v-if="limitDelay" class="col-md-6 col-xl-3">
                        Maximum start delay (s)
                        <input
                            v-model.number="settings.max_wait"
                            class="form-control"
                            type="number"
                            min="0"
                            max="3600"
                            step="0.1"
                            required
                        >
                    </label>
                    <label class="col-md-6 col-xl-3">
                        Handover margin (s)
                        <input v-model.number="settings.margin" class="form-control" type="number" min="0" max="5" step="0.1" required>
                    </label>
                    <label class="col-md-6 col-xl-3">
                        Maximum ducking (dB)
                        <input v-model.number="settings.duck_db" class="form-control" type="number" min="-20" max="0" step="0.5" required>
                    </label>
                    <label class="col-md-6 col-xl-3">
                        Jingle gain
                        <input v-model.number="settings.jingle_gain" class="form-control" type="number" min="0" max="2" step="0.05" required>
                    </label>
                </div>
                <p class="form-text">Sidechain attack: 150 ms · release: 750 ms. AutoCue supplies jingle start, handover and tail.</p>
                <button class="btn btn-primary" :disabled="saving">Save settings</button>
                <p class="form-text mb-0">Edit Intro End in the waveform below, under Music Files → Custom Fields, or through the intro API.</p>
            </form>
            </div>
        </section>
        <section v-if="!loading" class="row g-3">
            <div class="col-lg-4">
            <aside class="card h-100" role="region" aria-labelledby="sweepers-cues-heading">
                <div class="card-header text-bg-primary">
                    <h2 id="sweepers-cues-heading" class="card-title mb-0">Intro cues</h2>
                </div>
                <div class="card-body">
                    <form class="search" @submit.prevent="loadMedia">
                        <label class="form-label" for="search">Find audio</label>
                        <div class="input-group">
                            <input id="search" v-model="search" class="form-control" type="search" placeholder="Artist, title or filename">
                            <button class="btn btn-secondary" type="submit">Search</button>
                        </div>
                    </form>
                    <p class="form-text mb-0">Up to 100 matches. Refine your search to find more.</p>
                </div>
                <div class="table-responsive media-list">
                    <table class="table align-middle table-striped table-hover mb-0">
                        <thead><tr><th scope="col">File</th><th scope="col">Intro End</th></tr></thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id" :class="{ 'table-active': selected?.id === row.id }">
                                <td class="file-name">
                                    <button type="button" class="btn btn-link text-start p-0" @click="select(row)">{{ row.path }}</button>
                                </td>
                                <td class="text-nowrap">{{ formatIntro(row.intro_end) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!rows.length" class="card-body text-muted mb-0">No matching files.</p>
            </aside>
            </div>

            <div class="col-lg-8">
            <article v-if="selected" class="card h-100">
                <div class="card-header text-bg-primary">
                    <h2 class="card-title mb-0 text-break">{{ selected.path }}</h2>
                </div>
                <div class="card-body">
                <p class="text-muted">Saved Intro End: {{ formatIntro(selected.intro_end) }} · Original file position</p>
                <Waveform
                    :key="selected.id"
                    ref="waveform"
                    :audio-url="selected.audio_url"
                    :waveform-url="selected.waveform_url"
                    :intro="marker"
                    @position="position = $event"
                    @intro="draft = $event"
                />
                <div class="d-flex flex-wrap gap-2 my-3">
                    <button class="btn btn-secondary" type="button" :disabled="!waveform?.ready" @click="draft = Math.round(position * 100) / 100">Set at playhead</button>
                    <button class="btn btn-secondary" type="button" :disabled="marker === null || !waveform?.ready" @click="waveform?.playAt(marker)">Listen at Intro</button>
                    <button class="btn btn-secondary" type="button" :disabled="marker === null || !waveform?.ready" @click="waveform?.playAt(marker - 3)">Listen −3 s</button>
                </div>
                <form @submit.prevent="saveIntro">
                    <label class="form-label">
                        Intro End (s)
                        <input v-model="draft" class="form-control" type="number" min="0" :max="selected.duration" step="0.01">
                    </label>
                    <p class="form-text">Move the marker, or seek in the waveform and set the cue at the playhead. Blank means unknown; zero means no intro.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-secondary" type="button" @click="draft = 0">No intro (0 s)</button>
                        <button class="btn btn-secondary" type="button" @click="draft = ''">Clear cue</button>
                        <button class="btn btn-primary" :disabled="saving || !validDraft">Save Intro End</button>
                    </div>
                </form>
                </div>
            </article>
            <article v-else class="card h-100"><div class="card-body text-muted">Select a file to view its intro cue.</div></article>
            </div>
        </section>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import Waveform from "./Waveform.vue";

const props = defineProps({ base: { type: String, required: true }, csrf: { type: String, required: true } });
const csrf = ref(props.csrf);
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
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf.value },
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
        csrf.value = state.csrf ?? csrf.value;
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

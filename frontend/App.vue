<template>
    <div class="sweepers-content">
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
            <p v-if="moduleStatus.state === 'active'" class="text-success small mb-3" data-module-status="active">{{ moduleStatus.message }}</p>
            <p v-else-if="moduleStatus.state" class="alert alert-warning" role="status" :data-module-status="moduleStatus.state">{{ moduleStatus.message }}</p>
            <form @submit.prevent="saveSettings">
                <div class="form-check form-switch mb-3">
                    <input id="sweepers-enabled" v-model="settings.enabled" class="form-check-input" type="checkbox" role="switch">
                    <label class="form-check-label" for="sweepers-enabled">Enable sweepers</label>
                </div>
                <label class="form-label d-block mb-3">
                    Sweeper playlist
                    <select v-model="settings.playlist_id" class="form-select">
                        <option :value="null">Choose an Advanced playlist</option>
                        <option v-for="playlist in playlists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.name }}
                        </option>
                    </select>
                </label>
                <div class="row g-3 mb-1">
                    <label class="form-label col-md-4">
                        Songs without sweeper after a jingle
                        <input v-model.number="settings.songs_after_jingle" class="form-control" type="number" min="0" max="1000" step="1" required>
                    </label>
                    <label class="form-label col-md-4">
                        Songs without sweeper after a sweeper
                        <input v-model.number="settings.songs_after_sweeper" class="form-control" type="number" min="0" max="1000" step="1" required>
                    </label>
                    <label class="form-label col-md-4">
                        Songs without sweeper before a jingle
                        <input v-model.number="settings.songs_before_jingle" class="form-control" type="number" min="0" :max="queueLength" step="1" required>
                    </label>
                </div>
                <p class="form-text mb-3">0 disables a rule. After a jingle or sweeper, that many complete songs must play without an overlay; the song carrying a sweeper does not count. Before a jingle uses AzuraCast's upcoming queue and is limited to its AutoDJ queue length ({{ queueLength }}). It is checked again until the overlay starts. Interrupting or unqueued jingles cannot be foreseen.</p>
                <div class="form-check form-switch mb-3">
                    <input id="sweepers-count-jingle-mode" v-model="settings.count_jingle_mode" class="form-check-input" type="checkbox" role="switch">
                    <label class="form-check-label" for="sweepers-count-jingle-mode">Count Jingle Mode playlists</label>
                </div>
                <label class="form-label d-block mb-1">
                    Additional jingle playlists
                    <select v-model="settings.jingle_playlist_ids" class="form-select" multiple size="5">
                        <option v-for="playlist in jinglePlaylists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.name }}{{ playlist.is_jingle ? ' (Jingle Mode)' : '' }}
                        </option>
                    </select>
                </label>
                <p class="form-text mb-3">Hold Ctrl/Cmd to select several. Tracks played from these playlists reset the spacing. The sweeper playlist always counts.</p>
                <div class="form-check form-switch mb-3">
                    <input id="sweepers-longest-fit" v-model="settings.longest_fit" class="form-check-input" type="checkbox" role="switch">
                    <label class="form-check-label" for="sweepers-longest-fit">Prefer the longest fitting sweepers</label>
                </div>
                <label v-if="settings.longest_fit" class="form-label d-block mb-1">
                    Intro length tolerance (%)
                    <input v-model.number="settings.length_tolerance" class="form-control" type="number" min="0" max="100" step="1" required>
                </label>
                <p v-if="settings.longest_fit" class="form-text mb-3">20% selects bodies covering 80–100% of the available intro, then rotates by oldest playback. If none qualify, use the longest fitting body. A small selection may repeat often.</p>
                <p v-else class="form-text mb-3">Rotate by oldest actual playback among all sweepers that fit.</p>
                <div class="form-check form-switch mb-1">
                    <input id="sweepers-limitDelay" v-model="limitDelay" class="form-check-input" type="checkbox" role="switch">
                    <label class="form-check-label" for="sweepers-limitDelay">Limit start delay</label>
                </div>
                <p class="form-text mb-3">Disabled: time the handover to the intro end. Enabled: if that would exceed the limit, start immediately. 0 always starts immediately.</p>
                <div class="row g-3 mb-3">
                    <label v-if="limitDelay" class="form-label col-md-6 col-xl-3">
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
                    <label class="form-label col-md-6 col-xl-3">
                        Handover margin (s)
                        <input v-model.number="settings.margin" class="form-control" type="number" min="0" max="5" step="0.1" required>
                    </label>
                    <label class="form-label col-md-6 col-xl-3">
                        Maximum ducking (dB)
                        <input v-model.number="settings.duck_db" class="form-control" type="number" min="-20" max="0" step="0.5" required>
                    </label>
                    <label class="form-label col-md-6 col-xl-3">
                        Jingle gain
                        <input v-model.number="settings.jingle_gain" class="form-control" type="number" min="0" max="2" step="0.05" required>
                    </label>
                </div>
                <p class="form-text">Sidechain attack: 150 ms · release: 750 ms. AutoCue supplies jingle start, handover and tail.</p>
                <button class="btn btn-primary" :disabled="saving">Save settings</button>
                <p class="form-text mb-0">All settings, including Enable sweepers, update live within about 5 seconds. Enable only decides whether sweepers are used; the module is loaded on every station either way. An audible sweeper finishes; old pending overlays are cancelled. New settings apply to following songs. Restart broadcasting only once after installing or updating the plugin.</p>
                <p class="form-text mb-0">Edit Intro End in the waveform below, under Music Files → Custom Fields, or through the intro API.</p>
            </form>
            </div>
        </section>
        <section v-if="!loading" class="row g-3">
            <div class="col-12">
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
                        <thead>
                            <tr>
                                <th scope="col">File</th>
                                <th scope="col">Duration</th>
                                <th scope="col">Intro End</th>
                                <th scope="col"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in visibleRows" :key="row.id">
                            <tr class="media-track" :class="{ 'table-active': selected?.id === row.id }">
                                <td class="file-name">
                                    <span>{{ row.path }}</span>
                                </td>
                                <td class="text-nowrap">{{ formatDuration(row.duration) }}</td>
                                <td class="text-nowrap">{{ formatIntro(row.intro_end) }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-primary" :aria-label="'Edit intro: ' + row.path" :aria-pressed="selected?.id === row.id" @click="select(row)">Edit intro</button>
                                </td>
                            </tr>
                            <tr v-if="selected?.id === row.id" class="intro-editor-row">
                                <td colspan="4" class="p-0">
            <article class="card border-0 shadow-none">
                <div class="card-header text-bg-primary d-flex justify-content-between align-items-center gap-2">
                    <h2 class="card-title mb-0 text-break">{{ selected.path }}</h2>
                    <button class="btn btn-sm btn-outline-light" type="button" @click="selected = null">Close editor</button>
                </div>
                <div class="card-body">
                <p class="text-muted">Saved Intro End: {{ formatIntro(selected.intro_end) }} · Original file position</p>
                <Waveform
                    :key="selected.id"
                    :ref="el => { if (selected?.id === row.id) waveform = el; }"
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
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <span class="text-muted" role="status">{{ rows.length ? rows.length + ' matching files (maximum 100)' : 'No matching files.' }}</span>
                    <nav aria-label="Intro cue pages">
                        <ul class="pagination mb-0">
                            <li class="page-item" :class="{ disabled: page === 1 }"><button class="page-link" type="button" :disabled="page === 1" @click="page--">Previous</button></li>
                            <li class="page-item disabled"><span class="page-link">{{ page }} / {{ pageCount }}</span></li>
                            <li class="page-item" :class="{ disabled: page === pageCount }"><button class="page-link" type="button" :disabled="page === pageCount" @click="page++">Next</button></li>
                        </ul>
                    </nav>
                </div>
            </aside>
            </div>

        </section>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import Waveform from "./Waveform.vue";

const props = defineProps({ base: { type: String, required: true }, csrf: { type: String, required: true } });
const csrf = ref(props.csrf);
const station = ref({});
const queueLength = ref(2);
const moduleStatus = ref({});
const settings = ref({});
const playlists = ref([]);
const jinglePlaylists = ref([]);
const rows = ref([]);
const page = ref(1);
const pageSize = 25;
const pageCount = computed(() => Math.max(1, Math.ceil(rows.value.length / pageSize)));
const visibleRows = computed(() => rows.value.slice((page.value - 1) * pageSize, page.value * pageSize));
const search = ref("");
const selected = ref(null);
const waveform = ref(null);
watch(page, () => { selected.value = null; });
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

function formatDuration(value) {
    const seconds = Math.max(0, Math.floor(Number(value) || 0));
    return Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0");
}

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
        page.value = 1;
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
        const result = await api("settings", settings.value);
        moduleStatus.value = result.module ?? moduleStatus.value;
        notice.value = (moduleStatus.value.state === "active"
            ? "Settings saved. Live updates are picked up within about 5 seconds; an audible sweeper finishes and old pending overlays are cancelled."
            : "Settings saved. They apply as soon as the sweeper module runs on this station (see the status above).")
            + (result.needs_restart ? " AzuraCast still has other pending changes requiring a restart." : "");
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
        queueLength.value = state.queue_length ?? 2;
        moduleStatus.value = state.module ?? {};
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

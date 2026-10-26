<template>
    <div class="sweepers-content">
        <ul v-if="standalone" class="nav nav-tabs mb-3" role="tablist">
            <li v-for="[key, label] in views" :key="key" class="nav-item" role="presentation">
                <button class="nav-link" :class="{ active: current === key }" type="button" role="tab" :aria-selected="current === key" @click="show(key)">{{ label }}</button>
            </li>
        </ul>
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
        <p v-if="notice" class="alert alert-info" role="status">{{ notice }}</p>
        <p v-if="loading" class="text-muted">Loading…</p>
        <p v-if="!loading && moduleStatus.state && moduleStatus.state !== 'active'" class="alert alert-warning" role="status" :data-module-status="moduleStatus.state">{{ moduleStatus.message }}</p>

        <section v-if="!loading && current === 'settings'" class="card mb-3" role="region" aria-labelledby="sweepers-settings-heading">
            <div class="card-header text-bg-primary d-flex justify-content-between align-items-center">
                <h2 id="sweepers-settings-heading" class="card-title mb-0">Sweeper Settings</h2>
                <a class="btn btn-sm btn-outline-light" :href="stationUrl">Station dashboard</a>
            </div>
            <div class="card-body">
            <p class="text-muted mb-2">Sweepers are short station drop-ins played over the instrumental intro of a song, while the music is ducked underneath. Configure them for {{ station.name || 'your station' }} below.</p>
            <p v-if="moduleStatus.state === 'active'" class="text-success small mb-3" data-module-status="active">{{ moduleStatus.message }}</p>
            <form class="sweepers-settings" @submit.prevent="saveSettings">
                <fieldset>
                    <legend>General</legend>
                    <div class="form-check form-switch">
                        <input id="sweepers-enabled" v-model="settings.enabled" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-enabled">Enable sweepers</label>
                    </div>
                    <div class="form-text mb-3">Switches sweepers on or off for this station.</div>
                    <label class="form-label d-block mb-0">
                        Sweeper playlist
                        <select v-model="settings.playlist_id" class="form-select">
                            <option :value="null">Choose an Advanced playlist</option>
                            <option v-for="playlist in playlists" :key="playlist.id" :value="playlist.id">
                                {{ playlist.name }}
                            </option>
                        </select>
                    </label>
                    <div class="form-text">The playlist that contains the sweepers. It must be an <strong>Advanced</strong> playlist, so the sweepers do not also play as normal songs.</div>
                </fieldset>

                <fieldset>
                    <legend>Song spacing</legend>
                    <p class="form-text mt-0">A song only gets a sweeper if all three rules allow it. <strong>0</strong> switches a rule off.</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label d-block mb-0">
                                Songs without sweeper after a jingle
                                <input v-model.number="settings.songs_after_jingle" class="form-control" type="number" min="0" max="1000" step="1" required>
                            </label>
                            <div class="form-text">Complete songs that must play after a jingle before the next sweeper.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block mb-0">
                                Songs without sweeper after a sweeper
                                <input v-model.number="settings.songs_after_sweeper" class="form-control" type="number" min="0" max="1000" step="1" required>
                            </label>
                            <div class="form-text">Songs that must play without a sweeper after a song that carried one. That song itself does not count.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block mb-0">
                                Songs without sweeper before a jingle
                                <input v-model.number="settings.songs_before_jingle" class="form-control" type="number" min="0" :max="queueLength" step="1" required>
                            </label>
                            <div class="form-text">No sweeper if a jingle is queued within this many following tracks. Maximum: the AutoDJ queue length ({{ queueLength }}).</div>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Jingle recognition</legend>
                    <p class="form-text mt-0">Which tracks count as jingles for the spacing rules. Jingles never get a sweeper themselves.</p>
                    <div class="form-check form-switch">
                        <input id="sweepers-count-jingle-mode" v-model="settings.count_jingle_mode" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-count-jingle-mode">Count Jingle Mode playlists</label>
                    </div>
                    <div class="form-text mb-3">Tracks from playlists that use AzuraCast's <em>Jingle Mode</em> count as jingles.</div>
                    <label class="form-label d-block mb-0">
                        Additional jingle playlists
                        <select v-model="settings.jingle_playlist_ids" class="form-select" multiple size="5">
                            <option v-for="playlist in jinglePlaylists" :key="playlist.id" :value="playlist.id">
                                {{ playlist.name }}{{ playlist.is_jingle ? ' (Jingle Mode)' : '' }}
                            </option>
                        </select>
                    </label>
                    <div class="form-text">Other playlists whose tracks count as jingles. Hold <kbd>Ctrl</kbd> (<kbd>Cmd</kbd> on Mac) to select several. The sweeper playlist always counts.</div>
                </fieldset>

                <fieldset>
                    <legend>Playlists without sweepers</legend>
                    <label class="form-label d-block mb-0">
                        Exclude these playlists
                        <select v-model="settings.excluded_playlist_ids" class="form-select" multiple size="5">
                            <option v-for="playlist in jinglePlaylists" :key="playlist.id" :value="playlist.id">{{ playlist.name }}</option>
                        </select>
                    </label>
                    <div class="form-text">Tracks from these playlists never get a sweeper and are never moved, for example news or pre-recorded shows. Selecting a playlist group covers all playlists in it. Hold <kbd>Ctrl</kbd> (<kbd>Cmd</kbd> on Mac) to select several.</div>
                </fieldset>

                <fieldset>
                    <legend>Sweeper selection</legend>
                    <div class="form-check form-switch">
                        <input id="sweepers-longest-fit" v-model="settings.longest_fit" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-longest-fit">Prefer the longest fitting sweepers</label>
                    </div>
                    <div class="form-text" :class="{ 'mb-3': settings.longest_fit }">
                        <strong>Off:</strong> all sweepers that fit the intro take turns; the one played longest ago comes next.
                        <strong>On:</strong> prefer sweepers that fill most of the intro. How close they must come is set by the <em>intro length tolerance</em>, which appears when this is switched on.
                    </div>
                    <template v-if="settings.longest_fit">
                        <label class="form-label d-block mb-0">
                            Intro length tolerance (%)
                            <input v-model.number="settings.length_tolerance" class="form-control" type="number" min="0" max="100" step="1" required>
                        </label>
                        <div class="form-text">How much shorter than the intro a sweeper may be, in percent of the intro. If none is long enough, the longest fitting sweeper plays. With few sweepers, the same one may repeat often.</div>
                    </template>
                    <div class="form-check form-switch mt-3">
                        <input id="sweepers-swap-for-intro" v-model="settings.swap_for_intro" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-swap-for-intro">Move a song with an intro forward</label>
                    </div>
                    <div class="form-text">
                        When a sweeper is due but the next song has no usable intro, it changes places with a later queued song that has one.
                        Listener requests and playlist songs can move; jingles (recognized as above) and songs from playlists without sweepers keep their place.
                        This changes the order AzuraCast planned.
                    </div>
                    <template v-if="settings.swap_for_intro">
                        <div class="form-check form-switch mt-2">
                            <input id="sweepers-swap-sequential" v-model="settings.swap_sequential" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" for="sweepers-swap-sequential">Also move songs from sequential playlists</label>
                        </div>
                        <div class="form-text">Off: songs from playlists played in sequential order keep their place. On: they may move one step within the queue as well.</div>
                    </template>
                </fieldset>

                <fieldset>
                    <legend>Timing</legend>
                    <p class="form-text mt-0">A sweeper is normally timed so that its voice ends just before the singing starts. Any echo or reverb tail may continue over the vocals.</p>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Handover margin (s)
                                <input v-model.number="settings.margin" class="form-control" type="number" min="0" max="5" step="0.1" required>
                            </label>
                            <div class="form-text">Safety gap between the end of the sweeper's voice and the start of the vocals.</div>
                        </div>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input id="sweepers-limitDelay" v-model="settings.limit_delay" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-limitDelay">Limit start delay</label>
                    </div>
                    <div class="form-text">
                        <strong>Off:</strong> always time the sweeper to the end of the intro, even if it starts late in a long intro.
                        <strong>On:</strong> if that would make the sweeper wait longer than the maximum start delay, it starts at the early start instead.
                    </div>
                    <div v-if="settings.limit_delay" class="row g-3 mt-0">
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Maximum start delay (s)
                                <input v-model.number="settings.max_wait" class="form-control" type="number" min="0" max="3600" step="0.1" required>
                            </label>
                            <div class="form-text">Longest wait after the song starts for a sweeper timed to the end of the intro. <strong>0</strong> always uses the early start.</div>
                        </div>
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Early start after song start (s)
                                <input v-model.number="settings.early_start" class="form-control" type="number" min="0" max="60" step="0.1" required>
                            </label>
                            <div class="form-text">When the wait would be too long, the sweeper starts this many seconds into the song. It never starts later than the timed position, so it still ends before the vocals.</div>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Sound</legend>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Maximum ducking (dB)
                                <input v-model.number="settings.duck_db" class="form-control" type="number" min="-20" max="0" step="0.5" required>
                            </label>
                            <div class="form-text">How far the music is lowered under the sweeper's voice at most. Lower values make the voice clearer; values closer to 0 are more subtle. The music dips only while the sweeper speaks and comes back right after.</div>
                        </div>
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Jingle gain (%)
                                <input v-model.number="jingleGainPercent" class="form-control" type="number" min="0" max="200" step="1" required>
                            </label>
                            <div class="form-text">Volume of the sweepers compared with the songs; <strong>100%</strong> = as loud as the songs.</div>
                        </div>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input id="sweepers-reverb" v-model="settings.reverb" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-reverb">Add reverb to the sweeper voice</label>
                    </div>
                    <div class="form-text" :class="{ 'mb-3': settings.reverb }">A gentle, warm reverb that gives the voice some space, like a produced jingle.</div>
                    <div v-if="settings.reverb" class="row g-3">
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Reverb intensity (%)
                                <input v-model.number="settings.reverb_intensity" class="form-control" type="number" min="0" max="100" step="5" required>
                            </label>
                            <div class="form-text">How much reverb is mixed in.</div>
                        </div>
                    </div>
                    <div class="sweepers-preview mt-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6 col-xl-4">
                                <label class="form-label d-block mb-0">
                                    Preview sweeper
                                    <select v-model="previewId" class="form-select" :disabled="!previewSweepers.length">
                                        <option v-for="sweeper in previewSweepers" :key="sweeper.id" :value="sweeper.id">{{ describe(sweeper) }}</option>
                                    </select>
                                </label>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-outline-primary" type="button" :disabled="!previewSupported || !previewId || previewLoading" @click="togglePreview">
                                    {{ previewLoading ? 'Loading…' : previewPlaying ? 'Stop' : 'Play' }}
                                </button>
                            </div>
                        </div>
                        <div class="form-text">
                            <template v-if="!previewSupported">This browser cannot play the preview.</template>
                            <template v-else-if="!settings.playlist_id">Choose a sweeper playlist above to preview its sweepers.</template>
                            <template v-else>Plays the sweeper in your browser with the reverb as set above, before saving. Switching the reverb or changing the intensity is heard right away. Volume and ducking are not part of the preview.</template>
                        </div>
                        <p v-if="previewError" class="text-danger small mb-0" role="alert">{{ previewError }}</p>
                    </div>
                </fieldset>

                <button class="btn btn-primary" :disabled="saving">Save settings</button>
                <div class="form-text mt-3">Note: changes apply immediately, no restart needed.</div>
            </form>
            </div>
        </section>
        <section v-if="!loading && current === 'intros'" class="row g-3">
            <div class="col-12">
            <aside class="card h-100" role="region" aria-labelledby="sweepers-cues-heading">
                <div class="card-header text-bg-primary">
                    <h2 id="sweepers-cues-heading" class="card-title mb-0">Intro Cues</h2>
                </div>
                <div class="card-body">
                    <p class="text-muted">The intro end is where the singing starts. <strong>0</strong> = no usable intro, empty = not known yet. Songs without an intro end get no sweeper.</p>
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

        <section v-if="!loading && current === 'history'" class="card mb-3" role="region" aria-labelledby="sweepers-history-heading">
            <div class="card-header text-bg-primary d-flex justify-content-between align-items-center">
                <h2 id="sweepers-history-heading" class="card-title mb-0">Sweeper Playback Timeline</h2>
                <button class="btn btn-sm btn-outline-light" type="button" @click="loadHistory">Refresh</button>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">Songs that played with a sweeper.</p>
            </div>
            <div class="table-responsive">
                <table class="table align-middle table-striped table-hover mb-0 sweepers-timeline">
                    <thead>
                        <tr>
                            <th scope="col">Date/Time</th>
                            <th scope="col">Song</th>
                            <th scope="col">Sweeper</th>
                            <th scope="col" class="text-end">Started after</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in history" :key="entry.at">
                            <td class="text-nowrap">
                                <div>{{ formatDate(entry.at) }}</div>
                                <small class="text-muted">{{ formatClock(entry.at) }}</small>
                            </td>
                            <td class="file-name">
                                <div class="timeline-title">{{ fileTitle(entry.song) }}</div>
                                <small v-if="fileArtist(entry.song)" class="text-muted timeline-artist">{{ fileArtist(entry.song) }}</small>
                                <div v-if="entry.moved" class="timeline-moved">
                                    <span class="badge text-bg-info">Moved forward</span>
                                    <small class="text-muted">{{ entry.moved_before ? 'ahead of ' + describe(entry.moved_before) : 'ahead of a song without intro' }}</small>
                                </div>
                            </td>
                            <td class="file-name">
                                <div class="timeline-title">{{ fileTitle(entry.sweeper) }}</div>
                                <small v-if="fileArtist(entry.sweeper)" class="text-muted timeline-artist">{{ fileArtist(entry.sweeper) }}</small>
                            </td>
                            <td class="text-nowrap text-end">{{ entry.offset === null ? '–' : entry.offset.toFixed(1) + ' s' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!history.length" class="card-body text-muted">No sweeper has played yet.</div>
        </section>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import Waveform from "./Waveform.vue";
import { createReverbPreview, reverbLevel } from "./reverb.js";

const props = defineProps({
    base: { type: String, required: true },
    csrf: { type: String, required: true },
    view: { type: String, default: "settings" },
    standalone: { type: Boolean, default: false },
});
const views = [["settings", "Settings"], ["intros", "Intros"], ["history", "Playback Timeline"]];
const current = ref(views.some(([key]) => key === props.view) ? props.view : "settings");
const history = ref([]);
const mediaLoaded = ref(false);
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
// Stored as a factor (1.0); shown and entered as a percentage.
const jingleGainPercent = computed({
    get: () => Math.round((settings.value.jingle_gain ?? 1) * 100),
    set: (percent) => { settings.value.jingle_gain = Number(percent) / 100; },
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

function formatDate(seconds) {
    return new Date(seconds * 1000).toLocaleDateString(undefined, { dateStyle: "medium" });
}

function formatClock(seconds) {
    return new Date(seconds * 1000).toLocaleTimeString(undefined, { timeStyle: "medium" });
}

function describe(file) {
    if (!file) return "Deleted file";
    const name = [file.artist, file.title].filter(Boolean).join(" – ");
    return name || file.path;
}

// Like AzuraCast's playback timeline: title first, artist below; untagged files show their path.
function fileTitle(file) {
    if (!file) return "Deleted file";
    return file.title || file.path;
}

function fileArtist(file) {
    return file?.title ? file.artist || "" : "";
}

async function loadHistory() {
    try {
        const result = await api("history");
        history.value = result.rows;
    } catch (exception) {
        error.value = exception.message;
    }
}

async function show(view) {
    current.value = view;
    notice.value = "";
    if (view === "intros" && !mediaLoaded.value) await loadMedia();
    if (view === "history") await loadHistory();
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

// Reverb preview: plays a sweeper of the selected playlist in the browser with the current, unsaved settings.
const AudioContextClass = typeof window === "undefined" ? undefined : (window.AudioContext ?? window.webkitAudioContext);
const previewSupported = Boolean(AudioContextClass);
const previewSweepers = ref([]);
const previewId = ref(null);
const previewPlaying = ref(false);
const previewLoading = ref(false);
const previewError = ref("");
let preview = null;
const previewLevel = computed(() => reverbLevel(settings.value.reverb, settings.value.reverb_intensity));

async function fetchAudio(url) {
    const response = await fetch(url, { credentials: "same-origin" });
    if (!response.ok) throw new Error("The sweeper file could not be loaded.");
    return response.arrayBuffer();
}

function stopPreview() {
    preview?.stop();
    previewPlaying.value = false;
}

async function loadPreviewSweepers(playlist) {
    stopPreview();
    previewSweepers.value = [];
    previewId.value = null;
    previewError.value = "";
    if (!playlist) return;
    try {
        previewSweepers.value = (await api(`media?playlist=${encodeURIComponent(playlist)}`)).rows;
        previewId.value = previewSweepers.value[0]?.id ?? null;
    } catch (exception) {
        previewError.value = exception.message;
    }
}

async function togglePreview() {
    if (previewPlaying.value) {
        stopPreview();
        return;
    }
    const sweeper = previewSweepers.value.find((row) => row.id === previewId.value);
    if (!sweeper) return;
    preview ??= createReverbPreview(AudioContextClass, fetchAudio);
    previewError.value = "";
    previewLoading.value = true;
    try {
        await preview.play(sweeper, previewLevel.value, () => { previewPlaying.value = false; });
        previewPlaying.value = true;
    } catch (exception) {
        previewError.value = exception.message || "The preview could not be played.";
    } finally {
        previewLoading.value = false;
    }
}

watch(() => settings.value.playlist_id, (playlist) => loadPreviewSweepers(playlist));
watch(previewLevel, (level) => preview?.setLevel(level));
watch(previewId, stopPreview);
watch(current, stopPreview);
onUnmounted(() => preview?.close());

async function loadMedia() {
    try {
        rows.value = (await api(`media?search=${encodeURIComponent(search.value)}`)).rows;
        mediaLoaded.value = true;
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
        notice.value = "Intro end saved.";
    });
}

async function saveSettings() {
    await save(async () => {
        const result = await api("settings", settings.value);
        moduleStatus.value = result.module ?? moduleStatus.value;
        notice.value = (moduleStatus.value.state === "active"
            ? "Settings saved."
            : "Settings saved. They apply once the sweeper module runs on this station (see the status above).")
            + (result.needs_restart ? " AzuraCast also reports other changes that need a broadcasting restart." : "");
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
        if (current.value === "intros") await loadMedia();
        if (current.value === "history") await loadHistory();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});
</script>

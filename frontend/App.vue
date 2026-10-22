<template>
    <header>
        <div>
            <a :href="stationUrl">← AzuraCast</a>
            <h1>Drop-ins</h1>
            <p>{{ station.name }}</p>
        </div>
        <nav aria-label="Plugin pages">
            <button type="button" :class="{ active: tab === 'intros' }" @click="tab = 'intros'">Intro cues</button>
            <button type="button" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">Settings</button>
        </nav>
    </header>

    <main>
        <p v-if="error" class="alert danger" role="alert">{{ error }}</p>
        <p v-if="notice" class="alert" role="status">{{ notice }}</p>
        <p v-if="loading">Loading…</p>

        <section v-if="tab === 'settings' && !loading" class="card">
            <h2>Drop-in settings</h2>
            <form @submit.prevent="saveSettings">
                <label class="check"><input v-model="settings.enabled" type="checkbox"> Enable drop-ins</label>
                <label>
                    Jingle playlist
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
            </form>
        </section>

        <section v-if="tab === 'intros' && !loading" class="layout">
            <aside class="card">
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
                            <span>{{ displayIntro(row) }}</span>
                        </button>
                    </li>
                </ul>
                <p v-if="!rows.length">No matching files.</p>
            </aside>

            <article v-if="selected" class="card">
                <h2>{{ selected.path }}</h2>
                <p>All positions refer to the original file.</p>
                <Waveform
                    :key="selected.id"
                    ref="waveform"
                    :audio-url="selected.audio_url"
                    :waveform-url="selected.waveform_url"
                    :intro="marker"
                    @position="position = $event"
                    @intro="draft.intro_end = $event"
                />
                <div class="toolbar">
                    <button type="button" @click="setAtPlayhead">Set Intro at playhead</button>
                    <button
                        type="button"
                        :disabled="marker === null"
                        @click="waveform?.playAt(marker)"
                    >
                        Listen at Intro
                    </button>
                    <button
                        type="button"
                        :disabled="marker === null"
                        @click="waveform?.playAt(marker - 3)"
                    >
                        Listen −3 s
                    </button>
                </div>
                <form @submit.prevent="saveIntro">
                    <div class="grid">
                        <label>
                            Intro End (s)
                            <input v-model="draft.intro_end" type="number" min="0" :max="selected.duration" step="0.01">
                        </label>
                        <label>
                            Driven by
                            <select v-model="draft.kind">
                                <option value="vocal">Vocals</option>
                                <option value="musical">Music</option>
                                <option value="30s+">Verified 30s+ opening</option>
                            </select>
                        </label>
                        <label>
                            Status
                            <select v-model="draft.status">
                                <option value="confirmed">Confirmed</option>
                                <option value="no_intro">No intro</option>
                                <option value="unknown">Not measured</option>
                                <option value="veto">Veto — block drop-ins</option>
                            </select>
                        </label>
                    </div>
                    <p>Blank means unknown. Zero means no usable intro.</p>
                    <label class="check">
                        <input v-model="draft.lock_manual" type="checkbox">
                        Protect this review from automatic updates
                    </label>
                    <button class="primary" :disabled="saving">Save intro</button>
                </form>
                <dl v-if="selected.timing" class="grid timings">
                    <div><dt>AutoCue cue-in</dt><dd>{{ selected.timing.cueIn.toFixed(3) }} s</dd></div>
                    <div><dt>AutoCue cue-out</dt><dd>{{ selected.timing.cueOut.toFixed(3) }} s</dd></div>
                    <div><dt>AutoCue handover</dt><dd>{{ selected.timing.handover.toFixed(3) }} s</dd></div>
                </dl>
            </article>
            <article v-else class="card"><p>Select a file to review its intro.</p></article>
        </section>
    </main>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import Waveform from "./Waveform.vue";
import { displayIntro, parseIntro } from "./cues.js";

const props = defineProps({ base: { type: String, required: true }, csrf: { type: String, required: true } });
const tab = ref("intros");
const station = ref({});
const settings = ref({});
const playlists = ref([]);
const rows = ref([]);
const search = ref("");
const selected = ref(null);
const position = ref(0);
const waveform = ref(null);
const loading = ref(true);
const saving = ref(false);
const notice = ref("");
const error = ref("");
const draft = reactive({ intro_end: "", kind: "vocal", status: "unknown", lock_manual: true });
const stationUrl = computed(() => `/station/${station.value.id ?? ""}`);
const marker = computed(() => {
    try {
        return parseIntro(draft.intro_end, selected.value?.duration ?? 0);
    } catch {
        return null;
    }
});

async function api(path, data) {
    const response = await fetch(`${props.base}/${path}`, {
        method: data === undefined ? "GET" : "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": props.csrf },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    const result = await response.json();
    if (!response.ok) {
        throw new Error(result.message || result.error?.message || "The request failed.");
    }

    return result;
}

async function loadMedia() {
    try {
        rows.value = (await api(`media?search=${encodeURIComponent(search.value)}`)).rows;
    } catch (exception) {
        error.value = exception.message;
    }
}

function select(row) {
    selected.value = row;
    position.value = 0;
    draft.intro_end = row.intro_end ?? row.review?.candidate ?? "";
    draft.kind = row.review?.kind ?? "vocal";
    const status = row.intro_end === null ? "unknown" : row.intro_end === 0 ? "no_intro" : "confirmed";
    draft.status = row.stale ? "unknown" : row.review?.status ?? status;
    draft.lock_manual = row.review?.manual ?? true;
}

function setAtPlayhead() {
    draft.intro_end = Math.round(position.value * 100) / 100;
    draft.status = "confirmed";
}

async function saveSettings() {
    await save(async () => {
        await api("settings", settings.value);
        notice.value = "Settings saved. Restart broadcasting in AzuraCast to apply them.";
    });
}

async function saveIntro() {
    await save(async () => {
        const id = selected.value.id;
        await api(`intro/${id}`, { ...draft, intro_end: parseIntro(draft.intro_end, selected.value.duration) });
        await loadMedia();
        const updated = rows.value.find((row) => row.id === id);
        if (updated) {
            select(updated);
        }
        notice.value = "Intro saved. Already-prefetched tracks may retain their previous plan.";
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

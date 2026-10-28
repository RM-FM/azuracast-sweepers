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
                        Sweeper playlists
                        <select v-model="settings.playlist_ids" class="form-select" multiple size="5">
                            <option v-for="playlist in playlists" :key="playlist.id" :value="playlist.id">
                                {{ playlistLabel(playlist) }}
                            </option>
                        </select>
                    </label>
                    <div class="form-text">One or more <strong>Advanced</strong> playlists with the sweepers, so they do not also play as normal songs. Playlists with a schedule are used only in their time slots; without a schedule they are always used. All playlists active at the same time share one rotation. Disabled playlists are skipped. Hold <kbd>Ctrl</kbd> (<kbd>Cmd</kbd> on Mac) to select several.</div>
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
                    <div class="form-text">Other playlists whose tracks count as jingles. Hold <kbd>Ctrl</kbd> (<kbd>Cmd</kbd> on Mac) to select several. The sweeper playlists always count.</div>
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
                    <div class="row g-3 mb-3">
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label d-block mb-0">
                                Other sweepers before a repeat
                                <input v-model.number="settings.sweeper_gap" class="form-control" type="number" min="0" max="100" step="1" required>
                            </label>
                            <div class="form-text">At least this many other sweepers play before the same sweeper is used again. If none of the others fits a song's intro, that song gets no sweeper. <strong>0</strong> allows repeats. A single sweeper can always repeat.</div>
                        </div>
                    </div>
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
                            <template v-else-if="!settings.playlist_ids.length">Choose a sweeper playlist above to preview its sweepers.</template>
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
                    <p class="text-muted">The intro end is where the singing starts. <strong>0</strong> = no usable intro, <strong>empty</strong> = not known yet. Songs without an intro end get no sweeper.</p>
                    <form class="search" @submit.prevent>
                        <div class="input-group">
                            <input id="search" v-model="search" class="form-control" type="search" aria-label="Search tracks" placeholder="Artist, title or filename">
                        </div>
                    </form>
                    <div class="form-check form-switch mt-3">
                        <input id="unknown-intros" v-model="unknownOnly" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="unknown-intros">Show unknown/untagged tracks</label>
                    </div>
                </div>
                <div class="table-responsive media-list">
                    <table class="table align-middle table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th scope="col">File</th>
                                <th scope="col">Duration</th>
                                <th scope="col">Intro End</th>
                                <th scope="col" class="text-end text-nowrap">
                                    <button
                                        class="btn btn-sm btn-secondary"
                                        type="button"
                                        :disabled="!listening && !scanning && (mediaLoading || !rows.length)"
                                        :aria-pressed="Boolean(listening || scanning)"
                                        :title="listening?.path"
                                        @click="listening || scanning ? stopListening() : startListening()"
                                    >
                                        <svg class="intro-play-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path :d="listening || scanning ? 'M3 3h10v10H3z' : 'M4 2l10 6-10 6z'" /></svg>
                                        {{ listening || scanning ? 'Stop' : 'Scan Intros' }}
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in visibleRows" :key="row.id">
                            <tr v-if="selected?.id !== row.id" class="media-track">
                                <td class="file-name">
                                    <span>{{ row.path }}</span>
                                </td>
                                <td class="text-nowrap">{{ formatDuration(row.duration) }}</td>
                                <td class="text-nowrap">{{ formatIntro(row.intro_end) }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-primary" :aria-label="'Edit intro: ' + row.path" @click="select(row)">Edit intro</button>
                                    <button type="button" class="btn btn-sm btn-secondary ms-2" :disabled="!canListen(row)" :aria-label="'Listen at intro: ' + row.path" @click="listenOne(row)">
                                        <svg class="intro-play-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 2l10 6-10 6z" /></svg>
                                        Intro
                                    </button>
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
                <p class="text-muted">Duration {{ formatDuration(selected.duration) }}</p>
                <Waveform
                    :key="selected.id"
                    :ref="el => { if (selected?.id === row.id) waveform = el; }"
                    :audio-url="selected.audio_url"
                    :waveform-url="selected.waveform_url"
                    :intro="marker"
                    @position="position = $event"
                    @intro="draft = $event"
                >
                    <template #controls="{ ready }">
                        <button class="btn btn-secondary" type="button" :disabled="!ready" @click="draft = Math.round(position * 100) / 100">Set</button>
                        <button class="btn btn-secondary" type="button" :disabled="marker === null || !ready" @click="waveform?.playAt(marker)">Listen Intro</button>
                    </template>
                </Waveform>
                <form class="d-flex flex-wrap align-items-end gap-2" @submit.prevent="saveIntro">
                    <label class="form-label mb-0">
                        Intro End (s)
                        <input v-model="draft" class="form-control" type="number" min="0" :max="selected.duration" step="0.01">
                    </label>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-secondary" type="button" @click="draft = 0">No Intro</button>
                        <button class="btn btn-secondary" type="button" @click="draft = ''">Clear</button>
                        <button class="btn btn-primary" :disabled="saving || !validDraft">Save Intro</button>
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
                    <span class="text-muted" role="status">{{ total ? total + ' matching files' : 'No matching files.' }}</span>
                    <nav aria-label="Intro cue pages">
                        <ul class="pagination mb-0">
                            <li class="page-item" :class="{ disabled: page === 1 || mediaLoading }"><button class="page-link" type="button" :disabled="page === 1 || mediaLoading" @click="changePage(page - 1)">Previous</button></li>
                            <li class="page-item disabled"><span class="page-link">{{ page }} / {{ pageCount }}</span></li>
                            <li class="page-item" :class="{ disabled: page === pageCount || mediaLoading }"><button class="page-link" type="button" :disabled="page === pageCount || mediaLoading" @click="changePage(page + 1)">Next</button></li>
                        </ul>
                    </nav>
                </div>
            </aside>
            </div>

        </section>

        <section v-if="!loading && current === 'history'" class="card mb-3" role="region" aria-labelledby="sweepers-history-heading">
            <div class="card-header text-bg-primary d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 id="sweepers-history-heading" class="card-title mb-0">Sweeper Playback Timeline</h2>
                <div class="d-flex align-items-center gap-3">
                    <div class="form-check form-switch mb-0">
                        <input id="sweepers-only-played" v-model="onlySweepers" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="sweepers-only-played">Only songs with a sweeper</label>
                    </div>
                    <button class="btn btn-sm btn-outline-light" type="button" @click="loadHistory">Refresh</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle table-hover mb-0 sweepers-timeline">
                    <thead>
                        <tr>
                            <th scope="col">Time</th>
                            <th scope="col">Track</th>
                            <th scope="col">Sweeper Status</th>
                            <th scope="col">Sweeper or reason</th>
                            <th scope="col" class="text-end">Intro</th>
                            <th scope="col" class="text-end">Started after</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in timeline" :key="entry.at" :class="rowClass(entry)">
                            <td class="text-nowrap">
                                <div>{{ formatClock(entry.at) }}</div>
                                <small class="text-muted">{{ formatDate(entry.at) }}</small>
                            </td>
                            <td class="file-name">
                                <div class="timeline-title">{{ fileTitle(entry.song) }}</div>
                                <small v-if="fileArtist(entry.song)" class="text-muted timeline-artist">{{ fileArtist(entry.song) }}</small>
                                <small v-if="entry.moved" class="timeline-moved" :title="entry.moved_before ? describe(entry.moved_before) : ''">
                                    ↑ Moved ahead of {{ entry.moved_before ? fileTitle(entry.moved_before) : 'a song without intro' }}
                                </small>
                            </td>
                            <td class="text-nowrap">
                                <span v-if="status(entry)" class="badge rounded-pill" :class="status(entry).class">{{ status(entry).label }}</span>
                            </td>
                            <td class="file-name">
                                <template v-if="entry.result === 'played'">
                                    <div class="timeline-title">{{ fileTitle(entry.sweeper) }}</div>
                                    <small v-if="fileArtist(entry.sweeper)" class="text-muted timeline-artist">{{ fileArtist(entry.sweeper) }}</small>
                                </template>
                                <template v-else>
                                    <div class="text-muted timeline-reason">{{ reasonText(entry) }}</div>
                                    <small v-if="unmovedReasons[entry.unmoved]" class="timeline-unmoved">{{ unmovedReasons[entry.unmoved] }}</small>
                                </template>
                            </td>
                            <td class="text-nowrap text-end">
                                <button v-if="entry.song?.id && entry.result !== 'jingle'" type="button" class="btn btn-sm btn-link timeline-intro" :aria-label="'Edit intro: ' + describe(entry.song)" title="Edit intro" @click="editIntro(entry.song.id)">{{ seconds(entry.song.intro) || 'Set' }} ✎</button>
                                <span v-else class="text-muted">{{ seconds(entry.song?.intro) }}</span>
                            </td>
                            <td class="text-nowrap text-end">{{ entry.result === 'played' ? seconds(entry.offset) : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!timeline.length" class="card-body text-muted">{{ onlySweepers ? 'No sweeper has played yet.' : 'No track has been recorded yet.' }}</div>
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
const total = ref(0);
const mediaLoading = ref(false);
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize)));
const visibleRows = computed(() => rows.value);
const search = ref("");
const unknownOnly = ref(false);
const selected = ref(null);
const waveform = ref(null);
watch(page, () => { selected.value = null; }, { flush: "sync" });
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

function fileTitle(file) {
    if (!file) return "Deleted file";
    return file.title || file.path;
}

function fileArtist(file) {
    return file?.title ? file.artist || "" : "";
}

const onlySweepers = ref(false);
const timeline = computed(() => (onlySweepers.value
    ? history.value.filter((entry) => entry.result === "played")
    : history.value));

const reasons = {
    no_intro: "No intro end set",
    intro_zero: "No intro",
    intro_before_cue: "Intro ends in the silence before the song starts",
    no_fit: "Intro too short for every sweeper",
    repeat: "Only fitting sweeper played too recently",
    no_sweepers: "No sweepers ready (empty playlist or not measured yet)",
    off_schedule: "No sweeper playlist scheduled now",
    jingle_follows: "A jingle follows soon",
    excluded: "Playlist excluded from sweepers",
    after_jingle: "Too soon after a jingle",
    after_sweeper: "Too soon after a sweeper",
    jingle: "Jingle",
    other: "Not from the AutoDJ queue",
    live: "Live DJ on air",
    disabled: "Sweepers switched off",
    late: "Sweeper start missed (too late)",
    skipped: "Song ended or was skipped before the sweeper",
    settings: "Cancelled by a settings change",
    unavailable: "Sweeper could not be loaded",
    error: "Planning error",
    not_planned: "Not planned (station just started)",
};

function reasonText(entry) {
    if (entry.result === "scheduled") {
        return isUpcoming(entry) ? "Sweeper coming up" : "Sweeper did not start";
    }
    return reasons[result(entry)] ?? "No sweeper";
}

// Entries logged before intro_zero existed reported a 0 s intro as intro_before_cue.
function result(entry) {
    return entry.result === "intro_before_cue" && entry.song?.intro === 0 ? "intro_zero" : entry.result;
}

const unmovedReasons = {
    move_none: "Not moved forward: no queued song has a fitting intro",
    move_jingle: "Not moved forward: a jingle would follow too soon",
    move_sequential: "Not moved forward: sequential playlist order",
};

function isUpcoming(entry) {
    return entry.result === "scheduled" && entry === history.value[0];
}

// Spacing rules and track types skip a sweeper by design; any other reason means a due sweeper could not play.
const intendedSkips = new Set([
    "jingle", "after_jingle", "after_sweeper", "jingle_follows", "excluded", "other", "live", "disabled", "intro_zero",
    "off_schedule",
]);

function status(entry) {
    if (entry.result === "played") return { label: "Played", class: "text-bg-success" };
    if (isUpcoming(entry)) return { label: "Upcoming", class: "text-bg-info" };
    if (intendedSkips.has(result(entry))) return null;
    return { label: "Missed", class: "text-bg-danger" };
}

function rowClass(entry) {
    if (entry.result === "played") return "timeline-played";
    if (entry.result === "jingle") return "timeline-jingle";
    return status(entry)?.label === "Missed" ? "timeline-missed" : "timeline-plain";
}

function seconds(value) {
    return typeof value === "number" ? `${value.toFixed(1)} s` : "";
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

function playlistLabel(playlist) {
    const notes = [playlist.scheduled ? "scheduled" : "", playlist.enabled === false ? "disabled" : ""].filter(Boolean);
    return notes.length ? `${playlist.name} (${notes.join(", ")})` : playlist.name;
}

async function loadPreviewSweepers(ids) {
    stopPreview();
    previewSweepers.value = [];
    previewId.value = null;
    previewError.value = "";
    if (!ids?.length) return;
    try {
        previewSweepers.value = (await api(`media?playlist=${ids.join(",")}`)).rows;
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

watch(() => settings.value.playlist_ids?.join(","), () => loadPreviewSweepers(settings.value.playlist_ids));
watch(previewLevel, (level) => preview?.setLevel(level));
watch(previewId, stopPreview);
watch(current, stopPreview);
onUnmounted(() => preview?.close());

let mediaRequest = 0;
let searchTimer;
let settingSearch = false;
const listening = ref(null);
const scanning = ref(false);
let audio = null;
let listeningRun = 0;

function canListen(row) {
    return Number.isFinite(row.intro_end) && row.intro_end >= 0 && row.intro_end < row.duration;
}

function clearAudio() {
    if (!audio) return;
    audio.onloadedmetadata = audio.ontimeupdate = audio.onended = audio.onerror = null;
    audio.pause();
    audio.removeAttribute("src");
    audio.load();
    audio = null;
    listening.value = null;
}

function stopListening() {
    listeningRun++;
    scanning.value = false;
    clearAudio();
}

function listeningError(exception, run) {
    if (run !== listeningRun) return;
    stopListening();
    error.value = exception.message || "The intro could not be played.";
}

function playIntro(row, run, next) {
    clearAudio();
    waveform.value?.pause();
    selected.value = null;
    stopPreview();
    listening.value = row;
    const player = new Audio(row.audio_url);
    audio = player;
    let finished = false;
    const finish = () => {
        if (finished || run !== listeningRun) return;
        finished = true;
        clearAudio();
        void next();
    };
    player.onloadedmetadata = () => {
        try {
            if (row.intro_end >= player.duration) {
                throw new Error("The saved Intro End is outside the playable audio.");
            }
            player.currentTime = row.intro_end;
        } catch (exception) {
            listeningError(exception, run);
        }
    };
    player.ontimeupdate = () => {
        if (player.currentTime >= row.intro_end + 5) finish();
    };
    player.onended = finish;
    player.onerror = () => listeningError(new Error(`Audio could not be loaded: ${row.path}`), run);
    void player.play().catch((exception) => listeningError(exception, run));
}

function listenOne(row) {
    stopListening();
    error.value = "";
    playIntro(row, listeningRun, stopListening);
}

async function scanNext(index, run) {
    try {
        while (run === listeningRun) {
            const next = rows.value.findIndex((row, i) => i >= index && canListen(row));
            if (next !== -1) {
                playIntro(rows.value[next], run, () => scanNext(next + 1, run));
                return;
            }
            if (page.value >= pageCount.value) {
                stopListening();
                notice.value = "Finished listening to all matching intros.";
                return;
            }
            if (!await loadMedia(page.value + 1) || run !== listeningRun) return;
            index = 0;
        }
    } catch (exception) {
        listeningError(exception, run);
    }
}

function startListening() {
    stopListening();
    error.value = "";
    notice.value = "";
    scanning.value = true;
    void scanNext(0, listeningRun);
}

function changePage(target) {
    stopListening();
    void loadMedia(target);
}

watch(search, () => {
    clearTimeout(searchTimer);
    if (settingSearch) return;
    stopListening();
    mediaRequest++;
    mediaLoaded.value = false;
    mediaLoading.value = true;
    searchTimer = setTimeout(() => { void loadMedia(); }, 300);
}, { flush: "sync" });
watch(unknownOnly, () => {
    if (settingSearch) return;
    clearTimeout(searchTimer);
    stopListening();
    mediaLoaded.value = false;
    void loadMedia();
}, { flush: "sync" });
watch(current, () => {
    stopListening();
    clearTimeout(searchTimer);
    mediaRequest++;
    mediaLoading.value = false;
}, { flush: "sync" });
onUnmounted(() => {
    stopListening();
    clearTimeout(searchTimer);
    mediaRequest++;
});

async function loadMedia(target = 1) {
    const request = ++mediaRequest;
    mediaLoading.value = true;
    error.value = "";
    try {
        const result = await api(`media?search=${encodeURIComponent(search.value)}${unknownOnly.value ? "&unknown=1" : ""}&page=${target}`);
        if (request !== mediaRequest) return false;
        rows.value = result.rows;
        total.value = result.total;
        mediaLoaded.value = true;
        page.value = target;
        const updated = rows.value.find((row) => row.id === selected.value?.id);
        if (updated) select(updated);
        else selected.value = null;
        return true;
    } catch (exception) {
        if (request === mediaRequest) {
            stopListening();
            error.value = exception.message;
        }
        return false;
    } finally {
        if (request === mediaRequest) mediaLoading.value = false;
    }
}

async function editIntro(id) {
    stopListening();
    current.value = "intros";
    notice.value = "";
    clearTimeout(searchTimer);
    const request = ++mediaRequest;
    mediaLoading.value = true;
    try {
        const [row] = (await api(`media?id=${encodeURIComponent(id)}`)).rows;
        if (request !== mediaRequest) return;
        settingSearch = true;
        unknownOnly.value = false;
        search.value = row.path;
        settingSearch = false;
        rows.value = [row];
        total.value = 1;
        mediaLoaded.value = true;
        page.value = 1;
        select(row);
    } catch (exception) {
        if (request === mediaRequest) error.value = exception.message;
    } finally {
        if (request === mediaRequest) mediaLoading.value = false;
    }
}

function select(row) {
    stopListening();
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
        if (unknownOnly.value) {
            const target = rows.value.length === 1 && page.value > 1 && result.intro_end !== null
                ? page.value - 1 : page.value;
            await loadMedia(target);
        }
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

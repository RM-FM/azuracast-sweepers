<template>
    <div class="sweepers-content">
        <ul v-if="standalone" class="nav nav-tabs mb-3" role="tablist">
            <li v-for="[key, label] in views" :key="key" class="nav-item" role="presentation">
                <button class="nav-link" :class="{ active: current === key }" type="button" role="tab" :aria-selected="current === key" @click="show(key)">{{ label }}</button>
            </li>
        </ul>
        <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-info" role="status">{{ notice }}</div>
        <div v-if="loading" class="d-flex justify-content-center py-3">
            <div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <div v-if="!loading && moduleStatus.state && moduleStatus.state !== 'active'" class="alert alert-warning" role="status" :data-module-status="moduleStatus.state">
            <p class="mb-0">{{ moduleStatus.message }}</p>
            <div v-if="restart && restartNeeded" class="buttons mt-2">
                <button type="button" class="btn btn-warning" :disabled="restarting" @click="restartBroadcasting">Restart Broadcasting</button>
            </div>
        </div>

        <section v-if="!loading && stateLoaded && current === 'settings'" class="card mb-3" role="region" aria-labelledby="sweepers-settings-heading">
            <div class="card-header text-bg-primary">
                <h2 id="sweepers-settings-heading" class="card-title my-0">Sweeper Settings</h2>
            </div>
            <form class="form sweepers-settings" @submit.prevent="saveSettings" @invalid.capture="revealInvalidSetting">
                <div class="card-body">
                    <nav class="nav nav-default nav-tabs mb-3" role="tablist" aria-label="Sweeper settings" @keydown="settingsTabKey">
                        <div v-for="[key, label] in settingsTabs" :key="key" class="nav-item" role="presentation">
                            <button :id="'sweepers-tab-' + key" class="nav-link" :class="{ active: settingsTab === key }" type="button" role="tab" :aria-selected="settingsTab === key" :aria-controls="'sweepers-panel-' + key" :tabindex="settingsTab === key ? 0 : -1" @click="settingsTab = key">{{ label }}</button>
                        </div>
                    </nav>
                    <div id="sweepers-panel-general" v-show="settingsTab === 'general'" class="sweepers-settings-panel" role="tabpanel" aria-labelledby="sweepers-tab-general" data-settings-tab="general">
                    <fieldset class="form-group">
                        <legend>Status</legend>
                        <p v-if="moduleStatus.state === 'active'" class="card-text text-success" data-module-status="active">{{ moduleStatus.message }}</p>
                        <p v-if="release" class="card-text text-muted small" data-plugin-version>Plugin version {{ installedLabel }}</p>
                        <div v-if="release?.latest" class="alert alert-info" role="status" data-plugin-update>
                            <p class="mb-1">Version {{ release.latest.version }} is available. {{ updateRestartText }}</p>
                            <a class="alert-link" :href="release.latest.url" target="_blank" rel="noopener">Release notes</a>
                            ·
                            <a class="alert-link" :href="release.repository_url + '#updating'" target="_blank" rel="noopener">Update steps</a>
                        </div>
                        <div class="row g-3">
                            <div class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-enabled" v-model="settings.enabled" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-enabled">Enable sweepers</label>
                                </div>
                                <div class="form-text">Switches sweepers on or off for this station.</div>
                            </div>
                        </div>
                    </fieldset>
                    <div class="row g-4 mt-0">
                    <fieldset class="form-group col-12" aria-describedby="sweepers-playlists-help">
                        <legend>Sweeper Playlists</legend>
                                <PlaylistPicker id="sweepers-playlist" v-model="settings.playlist_ids" title="Sweeper playlists" :playlists="playlists" :label="playlistLabel" />
                                <div id="sweepers-playlists-help" class="form-text">One or more <strong>Advanced</strong> playlists with the sweepers, so they do not also play as normal songs. Playlists with a schedule are used only in their time slots; without a schedule they are always used. All playlists active at the same time share one rotation. Disabled playlists are skipped.</div>
                    </fieldset>

                    <fieldset class="form-group col-12">
                        <legend>Exclude Playlists from Sweepers</legend>
                        <div class="row g-3">
                            <fieldset class="form-group col-md-12">
                                <legend class="form-label">Exclude these playlists</legend>
                                <PlaylistPicker id="sweepers-excluded" v-model="settings.excluded_playlist_ids" title="Excluded playlists" :playlists="jinglePlaylists" />
                                <div class="form-text">Tracks from these playlists never get a sweeper and are never moved, for example news or pre-recorded shows. Selecting a playlist group covers all playlists in it.</div>
                            </fieldset>
                        </div>
                    </fieldset>
                    </div>
                    </div>

                    <div id="sweepers-panel-spacing" v-show="settingsTab === 'spacing'" class="sweepers-settings-panel" role="tabpanel" aria-labelledby="sweepers-tab-spacing" data-settings-tab="spacing">
                    <div class="row g-4">
                    <fieldset class="form-group col-12">
                        <legend>Song Spacing</legend>
                        <p>A song only gets a sweeper if all three rules allow it. <strong>0</strong> switches a rule off.</p>
                        <div class="row g-3">
                            <div class="form-group col-12 col-md-6">
                                <label class="form-label" for="sweepers-after-jingle">Songs without sweeper after a jingle</label>
                                <input id="sweepers-after-jingle" v-model.number="settings.songs_after_jingle" class="form-control" type="number" min="0" max="1000" step="1" required>
                                <div class="form-text">Complete songs that must play after a jingle before the next sweeper.</div>
                            </div>
                            <div class="form-group col-12 col-md-6">
                                <label class="form-label" for="sweepers-after-sweeper">Songs without sweeper after a sweeper</label>
                                <input id="sweepers-after-sweeper" v-model.number="settings.songs_after_sweeper" class="form-control" type="number" min="0" max="1000" step="1" required>
                                <div class="form-text">Songs that must play without a sweeper after a song that carried one. That song itself does not count.</div>
                            </div>
                            <div class="form-group col-12 col-md-6">
                                <label class="form-label" for="sweepers-before-jingle">Songs without sweeper before a jingle</label>
                                <input id="sweepers-before-jingle" v-model.number="settings.songs_before_jingle" class="form-control" type="number" min="0" :max="queueLength" step="1" required>
                                <div class="form-text">No sweeper if a jingle is queued within this many following tracks. Maximum: the AutoDJ queue length ({{ queueLength }}).</div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="form-group col-12">
                        <legend>Jingle Recognition</legend>
                        <p>Which tracks count as jingles for the spacing rules. Jingles never get a sweeper themselves.</p>
                        <div class="row g-3">
                            <div class="form-group col-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-count-jingle-mode" v-model="settings.count_jingle_mode" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-count-jingle-mode">Count Jingle Mode playlists</label>
                                </div>
                                <div class="form-text">Tracks from playlists that use AzuraCast's <em>Jingle Mode</em> count as jingles.</div>
                            </div>
                            <fieldset class="form-group col-12">
                                <legend class="form-label">Additional jingle playlists</legend>
                                <PlaylistPicker id="sweepers-jingle" v-model="settings.jingle_playlist_ids" title="Additional jingle playlists" :playlists="jinglePlaylists" :label="(playlist) => playlist.name + (playlist.is_jingle ? ' (Jingle Mode)' : '')" />
                                <div class="form-text">Other playlists whose tracks count as jingles. The sweeper playlists always count.</div>
                            </fieldset>
                        </div>
                    </fieldset>
                    </div>
                    </div>

                    <div id="sweepers-panel-selection" v-show="settingsTab === 'selection'" class="sweepers-settings-panel" role="tabpanel" aria-labelledby="sweepers-tab-selection" data-settings-tab="selection">
                    <div class="row g-4">
                    <fieldset class="form-group col-12">
                        <legend>Sweeper Selection</legend>
                        <div class="row g-3">
                            <div class="form-group col-md-6">
                                <label class="form-label" for="sweepers-gap">Other sweepers before a repeat</label>
                                <input id="sweepers-gap" v-model.number="settings.sweeper_gap" class="form-control" type="number" min="0" max="100" step="1" required>
                                <div class="form-text">At least this many other sweepers play before the same sweeper is used again. If none of the others fits a song's intro, that song gets no sweeper. <strong>0</strong> allows repeats. A single sweeper can always repeat.</div>
                            </div>
                            <div class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-longest-fit" v-model="settings.longest_fit" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-longest-fit">Prefer the longest fitting sweepers</label>
                                </div>
                                <div class="form-text">
                                    <strong>Off:</strong> all sweepers that fit the intro take turns; the one played longest ago comes next.
                                    <strong>On:</strong> prefer sweepers that fill most of the intro. How close they must come is set by the <em>intro length tolerance</em>, which appears when this is switched on.
                                </div>
                            </div>
                            <div v-if="settings.longest_fit" class="form-group col-md-6">
                                <label class="form-label" for="sweepers-tolerance">Intro length tolerance (%)</label>
                                <input id="sweepers-tolerance" v-model.number="settings.length_tolerance" class="form-control" type="number" min="0" max="100" step="1" required>
                                <div class="form-text">Preferred sweepers may be this much shorter than the available intro. If none in this range is eligible, regular rotation chooses another fitting sweeper. Repeat, reservation and timing rules still apply.</div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="form-group col-12">
                        <legend>Improve Sweeper-to-Song Matching</legend>
                        <div class="row g-3">
                            <div class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-swap-for-intro" v-model="settings.swap_for_intro" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-swap-for-intro">Move a song with an intro forward</label>
                                </div>
                                <div class="form-text">When a sweeper is due but the next song has no usable intro, it changes places with a later queued song that has one.</div>
                            </div>
                            <div class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-swap-from-playlist" v-model="settings.swap_from_playlist" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-swap-from-playlist">Swap with another song from same playlist</label>
                                </div>
                                <div class="form-text">
                                    If no queued song can be moved forward, try a fitting song from the same playlist using AzuraCast's playlist order and repetition rules.
                                    The replaced song returns to the playlist rotation, not directly to the end of the playback queue. Either option can be used on its own.
                                </div>
                            </div>
                            <div v-if="settings.swap_from_playlist" class="form-group col-md-6">
                                <label class="form-label" for="sweepers-replacement-limit">Maximum replacements per song</label>
                                <input id="sweepers-replacement-limit" v-model.number="settings.replacement_limit" class="form-control" type="number" min="0" max="1000" step="1" required>
                                <div class="form-text">After this many replacements, the song keeps its next turn, even without a sweeper. The count resets when it starts playing. 0 allows unlimited replacements.</div>
                            </div>
                            <div v-if="settings.swap_for_intro || settings.swap_from_playlist" class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-swap-sequential" v-model="settings.swap_sequential" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-swap-sequential">Also move or swap songs from sequential playlists</label>
                                </div>
                                <div class="form-text">Off: songs from sequential playlists keep their place. On: both matching options may change their order.</div>
                            </div>
                        </div>
                    </fieldset>

                    </div>
                    <fieldset class="form-group mt-4">
                        <legend>Timing</legend>
                        <p>A sweeper is normally timed so that its voice ends just before the singing starts. Any echo or reverb tail may continue over the vocals.</p>
                        <div class="row g-3">
                            <div class="form-group col-md-6">
                                <label class="form-label" for="sweepers-margin">Handover margin (s)</label>
                                <input id="sweepers-margin" v-model.number="settings.margin" class="form-control" type="number" min="0" max="5" step="0.1" required>
                                <div class="form-text">Safety gap between the end of the sweeper's voice and the start of the vocals.</div>
                            </div>
                            <div class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-limitDelay" v-model="settings.limit_delay" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-limitDelay">Limit start delay</label>
                                </div>
                                <div class="form-text">
                                    <strong>Off:</strong> always time the sweeper to the end of the intro, even if it starts late in a long intro.
                                    <strong>On:</strong> if that would make the sweeper wait longer than the maximum start delay, it starts at the early start instead.
                                </div>
                            </div>
                            <template v-if="settings.limit_delay">
                                <div class="form-group col-md-6">
                                    <label class="form-label" for="sweepers-max-wait">Maximum start delay (s)</label>
                                    <input id="sweepers-max-wait" v-model.number="settings.max_wait" class="form-control" type="number" min="0" max="3600" step="0.1" required>
                                    <div class="form-text">Longest wait after the song starts for a sweeper timed to the end of the intro. <strong>0</strong> always uses the early start.</div>
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="form-label" for="sweepers-early-start">Early start after song start (s)</label>
                                    <input id="sweepers-early-start" v-model.number="settings.early_start" class="form-control" type="number" min="0" max="60" step="0.1" required>
                                    <div class="form-text">When the wait would be too long, the sweeper starts this many seconds into the song. It never starts later than the timed position, so it still ends before the vocals.</div>
                                </div>
                            </template>
                        </div>
                    </fieldset>

                    </div>
                    <div id="sweepers-panel-audio" v-show="settingsTab === 'audio'" class="sweepers-settings-panel" role="tabpanel" aria-labelledby="sweepers-tab-audio" data-settings-tab="audio">
                    <div class="row g-4">
                    <fieldset class="form-group col-12">
                        <legend>Sound</legend>
                        <div class="row g-3">
                            <div class="form-group col-md-6">
                                <label class="form-label" for="sweepers-duck">Maximum ducking (dB)</label>
                                <input id="sweepers-duck" v-model.number="settings.duck_db" class="form-control" type="number" min="-20" max="0" step="0.5" required>
                                <div class="form-text">How far the music is lowered under the sweeper's voice at most. Lower values make the voice clearer; values closer to 0 are more subtle. The music dips only while the sweeper speaks and comes back right after.</div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="form-label" for="sweepers-gain">Jingle gain (%)</label>
                                <input id="sweepers-gain" v-model.number="jingleGainPercent" class="form-control" type="number" min="0" max="200" step="1" required>
                                <div class="form-text">Volume of the sweepers compared with the songs; <strong>100%</strong> = as loud as the songs.</div>
                            </div>
                            <div class="form-group col-md-12">
                                <div class="form-check form-switch">
                                    <input id="sweepers-reverb" v-model="settings.reverb" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="sweepers-reverb">Add reverb to the sweeper voice</label>
                                </div>
                                <div class="form-text">A gentle, warm reverb that gives the voice some space, like a produced jingle.</div>
                            </div>
                            <div v-if="settings.reverb" class="form-group col-md-6">
                                <label class="form-label" for="sweepers-reverb-intensity">Reverb intensity (%)</label>
                                <input id="sweepers-reverb-intensity" v-model.number="settings.reverb_intensity" class="form-control" type="number" min="0" max="100" step="5" required>
                                <div class="form-text">How much reverb is mixed in.</div>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="form-group col-12">
                        <legend>Preview</legend>
                        <div class="row g-3">
                            <div class="form-group col-12 col-md-6 sweepers-preview">
                                <label class="form-label" for="sweepers-preview">Preview sweeper</label>
                                <div class="input-group">
                                    <select id="sweepers-preview" v-model="previewId" class="form-select" :disabled="!previewSweepers.length">
                                        <option v-for="sweeper in previewSweepers" :key="sweeper.id" :value="sweeper.id">{{ describe(sweeper) }}</option>
                                    </select>
                                    <button class="btn btn-secondary" type="button" :disabled="!previewSupported || !previewId || previewLoading" @click="togglePreview">
                                        <Icon :name="previewPlaying ? 'stop' : 'play'" />
                                        <span>{{ previewLoading ? 'Loading…' : previewPlaying ? 'Stop' : 'Play' }}</span>
                                    </button>
                                </div>
                                <div class="form-text">
                                    <template v-if="!previewSupported">This browser cannot play the preview.</template>
                                    <template v-else-if="!settings.playlist_ids.length">Choose a sweeper playlist in General to preview its sweepers.</template>
                                    <template v-else>Plays the sweeper in your browser with the reverb as set above, before saving. Switching the reverb or changing the intensity is heard right away. Volume and ducking are not part of the preview.</template>
                                </div>
                                <div v-if="previewError" class="invalid-feedback d-block" role="alert">{{ previewError }}</div>
                            </div>
                        </div>
                    </fieldset>
                    </div>

                    </div>
                    <div class="buttons mt-3">
                        <button type="submit" class="btn btn-lg btn-primary" :disabled="saving">Save Changes</button>
                    </div>
                </div>
            </form>
        </section>

        <section v-if="!loading && stateLoaded && current === 'intros'" class="card mb-3" role="region" aria-labelledby="sweepers-cues-heading">
            <div class="card-header text-bg-primary">
                <div class="d-lg-flex align-items-center">
                    <h2 id="sweepers-cues-heading" class="card-title flex-fill my-0">Intro Manager</h2>
                    <div class="flex-shrink buttons ms-lg-2 mt-2 mt-lg-0">
                        <button
                            class="btn btn-dark sweepers-scan"
                            type="button"
                            :disabled="!listening && !scanning && (mediaLoading || !rows.length)"
                            :aria-pressed="Boolean(listening || scanning)"
                            :title="listening?.path"
                            @click="listening || scanning ? stopListening() : startListening()"
                        >
                            <Icon :name="listening || scanning ? 'stop' : 'play'" />
                            <span>{{ listening || scanning ? 'Stop' : 'Scan Intros' }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body pb-0">
                <p class="card-text"><strong>0</strong> = no usable intro, <strong>empty</strong> = not known yet. Songs without an intro end get no sweeper. <em>Scan Intros</em> plays five seconds from every intro end, page by page, until you stop it.</p>
            </div>
            <div class="datatable-wrapper">
                <DataToolbar
                    v-model:search="search"
                    :per-page="mediaPerPage"
                    search-id="search"
                    search-label="Search tracks"
                    :page="page"
                    :total="total"
                    label="Intro cue pages"
                    @update:per-page="changeMediaPerPage"
                    @page="changePage"
                    @refresh="changePage(page)"
                >
                    <template #filters>
                        <div class="form-check form-switch text-nowrap">
                            <input id="unknown-intros" v-model="unknownOnly" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" for="unknown-intros">Only unknown/untagged tracks</label>
                        </div>
                    </template>
                </DataToolbar>
                <div class="datatable-main table-responsive media-list">
                    <table class="table align-middle table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th scope="col">File</th>
                                <th scope="col">Duration</th>
                                <th scope="col">Intro End</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="mediaLoading && !rows.length">
                                <td colspan="4" class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div></td>
                            </tr>
                            <tr v-else-if="!rows.length">
                                <td colspan="4" class="text-center p-5">No records.</td>
                            </tr>
                            <template v-for="row in rows" :key="row.id">
                            <tr v-if="selected?.id !== row.id" class="media-track">
                                <td class="file-name">
                                    <span>{{ row.path }}</span>
                                </td>
                                <td class="text-nowrap">{{ formatDuration(row.duration) }}</td>
                                <td class="text-nowrap">{{ formatIntro(row.intro_end) }}</td>
                                <td class="text-end text-nowrap">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-primary" :aria-label="'Edit intro: ' + row.path" @click="select(row)">Edit intro</button>
                                        <button type="button" class="btn btn-secondary" :disabled="!canListen(row)" :aria-label="'Listen at intro: ' + row.path" @click="listenOne(row)">
                                            <Icon name="play" />
                                            <span>Intro</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="selected?.id === row.id" class="intro-editor-row">
                                <td colspan="4" class="p-0">
                                    <article class="card border-0 shadow-none">
                                        <div class="card-header text-bg-primary">
                                            <h2 class="card-title my-0 text-break">{{ selected.path }}</h2>
                                        </div>
                                        <div class="card-body">
                                            <p class="card-text">Duration {{ formatDuration(selected.duration) }}</p>
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
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-primary" type="button" :disabled="!ready" @click="draft = Math.round(position * 100) / 100">Set Intro</button>
                                                        <button class="btn btn-secondary" type="button" :disabled="!ready" @click="draft = 0">No Intro</button>
                                                    </div>
                                                </template>
                                            </Waveform>
                                            <p class="card-text mt-3 mb-0" data-intro-value>Intro End: {{ formatIntro(marker) }}</p>
                                        </div>
                                        <div class="card-footer d-flex justify-content-end gap-2">
                                            <button class="btn btn-secondary" type="button" @click="selected = null">Close</button>
                                            <button class="btn btn-primary" type="button" :disabled="saving || !validDraft" @click="saveIntro">Save Changes</button>
                                        </div>
                                    </article>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div v-if="mediaPerPage > 0" class="datatable-toolbar-bottom card-body">
                    <Pagination :page="page" :total="total" :per-page="mediaPerPage" label="Intro cue pages" @change="changePage" />
                </div>
            </div>
        </section>

        <section v-if="!loading && stateLoaded && current === 'history'" class="card mb-3" role="region" aria-labelledby="sweepers-history-heading">
            <div class="card-header text-bg-primary">
                <div class="d-lg-flex align-items-center">
                    <h2 id="sweepers-history-heading" class="card-title flex-fill my-0">Sweeper Playback Timeline</h2>
                    <div class="flex-shrink buttons ms-lg-2 mt-2 mt-lg-0">
                        <VueDatePicker v-bind="pickerOptions" :model-value="historyRange" @update:model-value="changeRange">
                            <template #dp-input="{ value }">
                                <button type="button" class="btn btn-dark dropdown-toggle sweepers-range">
                                    <Icon name="dateRange" />
                                    <span>{{ value }}</span>
                                </button>
                            </template>
                        </VueDatePicker>
                    </div>
                </div>
            </div>
            <div class="datatable-wrapper">
                <DataToolbar
                    v-model:search="historySearch"
                    :per-page="historyPerPage"
                    search-id="sweepers-history-search"
                    search-label="Search timeline"
                    :page="historyPage"
                    :total="historyTotal"
                    label="Timeline pages"
                    @update:per-page="changeHistoryPerPage"
                    @page="loadHistory"
                    @refresh="refreshHistory"
                >
                    <template #filters>
                        <div class="form-check form-switch text-nowrap">
                            <input id="sweepers-only-played" v-model="onlySweepers" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" for="sweepers-only-played">Only songs with a sweeper</label>
                        </div>
                    </template>
                </DataToolbar>
                <div class="datatable-main table-responsive">
                    <table class="table align-middle table-hover mb-0 sweepers-timeline">
                        <thead>
                            <tr>
                                <th scope="col">Date/Time (Station)</th>
                                <th scope="col">Track</th>
                                <th scope="col">Sweeper Status</th>
                                <th scope="col">Sweeper or Reason</th>
                                <th scope="col" class="text-end">Intro</th>
                                <th scope="col" class="text-end">Started After</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="historyLoading && !history.length">
                                <td colspan="6" class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div></td>
                            </tr>
                            <tr v-else-if="!history.length">
                                <td colspan="6" class="text-center p-5">No records.</td>
                            </tr>
                            <tr v-for="entry in history" :key="entry.at" :class="rowClass(entry)">
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
                                    <small v-if="entry.replaced" class="timeline-moved" :title="entry.replaced_before ? describe(entry.replaced_before) : ''">
                                        Replaced {{ entry.replaced_before ? fileTitle(entry.replaced_before) : 'a song without intro' }} from the same playlist
                                    </small>
                                </td>
                                <td class="text-nowrap">
                                    <span v-if="status(entry)" class="badge" :class="status(entry).class">{{ status(entry).label }}</span>
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
                <div v-if="historyPerPage > 0" class="datatable-toolbar-bottom card-body">
                    <Pagination :page="historyPage" :total="historyTotal" :per-page="historyPerPage" label="Timeline pages" @change="loadHistory" />
                </div>
            </div>
        </section>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { VueDatePicker } from "@vuepic/vue-datepicker";
import DataToolbar from "./DataToolbar.vue";
import Icon from "./Icon.vue";
import Pagination from "./Pagination.vue";
import PlaylistPicker from "./PlaylistPicker.vue";
import Waveform from "./Waveform.vue";
import { formatStationTime, lastDay, presetRanges, startOfDay } from "./dates.js";
import { createReverbPreview, reverbLevel } from "./reverb.js";

const props = defineProps({
    base: { type: String, required: true },
    csrf: { type: String, required: true },
    view: { type: String, default: "settings" },
    standalone: { type: Boolean, default: false },
});
const views = [["settings", "Settings"], ["intros", "Intro Manager"], ["history", "Playback Timeline"]];
const current = ref(views.some(([key]) => key === props.view) ? props.view : "settings");
const settingsTabs = [["general", "General"], ["spacing", "Spacing & Jingles"], ["selection", "Selection & Timing"], ["audio", "Audio & Preview"]];
const settingsTab = ref("general");

function settingsTabKey(event) {
    if (event.target.getAttribute("role") !== "tab") return;
    const index = settingsTabs.findIndex(([key]) => key === settingsTab.value);
    let target;
    if (event.key === "ArrowRight") target = (index + 1) % settingsTabs.length;
    else if (event.key === "ArrowLeft") target = (index + settingsTabs.length - 1) % settingsTabs.length;
    else if (event.key === "Home") target = 0;
    else if (event.key === "End") target = settingsTabs.length - 1;
    else return;
    event.preventDefault();
    settingsTab.value = settingsTabs[target][0];
    document.getElementById("sweepers-tab-" + settingsTab.value)?.focus();
}

async function revealInvalidSetting(event) {
    const input = event.target;
    const tab = input.closest("[data-settings-tab]")?.dataset.settingsTab;
    if (!tab || tab === settingsTab.value) return;
    event.preventDefault();
    settingsTab.value = tab;
    await nextTick();
    input.focus();
    input.reportValidity();
}
const history = ref([]);
const mediaLoaded = ref(false);
const csrf = ref(props.csrf);
const station = ref({});
const queueLength = ref(2);
const moduleStatus = ref({});
const restart = ref(null);
const restarting = ref(false);
const restartNeeded = computed(() => ["outdated", "restart"].includes(moduleStatus.value.state));
const release = ref(null);
const installedLabel = computed(() => {
    const { version, branch, commit } = release.value?.installed ?? {};
    const checkout = [branch, commit].filter(Boolean).join(" @ ");
    return [version ?? "unknown", checkout && `(${checkout})`].filter(Boolean).join(" ");
});
const updateRestartText = computed(() => ({
    required: "Updating requires restarting broadcasting once per station.",
    not_required: "It applies without restarting broadcasting.",
    unknown: "Check the release notes for whether broadcasting must be restarted.",
})[release.value?.restart] ?? "");
const settings = ref({});
const playlists = ref([]);
const jinglePlaylists = ref([]);
const rows = ref([]);
const page = ref(1);
const mediaPerPage = ref(25);
const total = ref(0);
const mediaLoading = ref(false);
const pageCount = computed(() => mediaPerPage.value > 0 ? Math.max(1, Math.ceil(total.value / mediaPerPage.value)) : 1);
const search = ref("");
const unknownOnly = ref(false);
const selected = ref(null);
const waveform = ref(null);
watch(page, () => { selected.value = null; }, { flush: "sync" });
const draft = ref("");
const position = ref(0);
const loading = ref(true);
const stateLoaded = ref(false);
const saving = ref(false);
const notice = ref("");
const error = ref("");
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
    return formatStationTime(seconds, stationZone.value, { dateStyle: "medium" });
}

function formatClock(seconds) {
    return formatStationTime(seconds, stationZone.value, { timeStyle: "medium" });
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
const historyRange = ref(lastDay());
// The default range follows the clock on refresh until a range is chosen.
let rollingRange = true;
const historySearch = ref("");
const historyPage = ref(1);
const historyPerPage = ref(25);
const historyTotal = ref(0);
const historyLoading = ref(false);
const retentionDays = ref(7);
let historyRequest = 0;
let historyTimer;
const stationZone = computed(() => station.value.timezone || undefined);
const pickerOptions = computed(() => ({
    dark: typeof document !== "undefined" && document.documentElement.getAttribute("data-bs-theme") === "dark",
    range: { partialRange: false },
    timeConfig: { enableTimePicker: true },
    presetDates: presetRanges(stationZone.value, retentionDays.value),
    actionRow: { selectBtnLabel: "Select", cancelBtnLabel: "Cancel", nowBtnLabel: "Now" },
    inputAttrs: { clearable: false },
    minDate: startOfDay(new Date(), stationZone.value, retentionDays.value),
    ...(stationZone.value ? { timezone: stationZone.value } : {}),
}));

const reasons = {
    no_intro: "No intro end set",
    intro_zero: "No intro",
    intro_before_cue: "Intro ends in the silence before the song starts",
    no_fit: "Intro too short for every sweeper",
    repeat: "Only fitting sweeper played too recently",
    reserved: "Fitting sweepers are reserved for upcoming songs",
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
    not_planned: "No planning decision recorded",
};

function reasonText(entry) {
    if (entry.result === "scheduled") {
        return entry.upcoming ? "Sweeper coming up" : "Sweeper did not start";
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
    replace_none: "Not replaced: no fitting song in this playlist",
    replace_limit: "Kept in place: song reached its replacement limit",
    replace_ineligible: "Not replaced: this queue entry cannot be replaced",
    replace_duplicates: "Not replaced: repetition rules exclude the fitting songs",
    replace_changed: "Not replaced: the queue or playlist changed during selection",
    replace_timeout: "Not replaced: playlist search reached its time limit",
    replace_busy: "Not replaced: AzuraCast is currently rebuilding the queue",
};

// Spacing rules and track types skip a sweeper by design; any other reason means a due sweeper could not play.
const intendedSkips = new Set([
    "jingle", "after_jingle", "after_sweeper", "jingle_follows", "excluded", "other", "live", "disabled", "intro_zero",
    "off_schedule",
]);

function status(entry) {
    if (entry.result === "played") return { label: "Played", class: "text-bg-success" };
    if (entry.upcoming) return { label: "Upcoming", class: "text-bg-info" };
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

async function loadHistory(target = 1) {
    clearTimeout(historyTimer);
    const request = ++historyRequest;
    historyLoading.value = true;
    error.value = "";
    const [start, end] = historyRange.value;
    const query = new URLSearchParams({
        start: start.toISOString(),
        end: end.toISOString(),
        search: historySearch.value,
        page: String(target),
        per_page: String(historyPerPage.value),
        ...(onlySweepers.value ? { played: "1" } : {}),
    });
    try {
        const result = await api(`history?${query}`);
        if (request !== historyRequest) return;
        history.value = result.rows;
        historyTotal.value = result.total;
        historyPage.value = result.page;
        retentionDays.value = result.retention_days ?? retentionDays.value;
    } catch (exception) {
        if (request === historyRequest) error.value = exception.message;
    } finally {
        if (request === historyRequest) historyLoading.value = false;
    }
}

function refreshHistory() {
    if (rollingRange) historyRange.value = lastDay();
    void loadHistory(historyPage.value);
}

function changeRange(range) {
    if (!Array.isArray(range) || range.length !== 2) return;
    rollingRange = false;
    historyRange.value = range;
    void loadHistory();
}

function changeHistoryPerPage(size) {
    historyPerPage.value = size;
    void loadHistory();
}

watch(historySearch, () => {
    clearTimeout(historyTimer);
    historyRequest++;
    historyTimer = setTimeout(() => { void loadHistory(); }, 300);
});
watch(onlySweepers, () => { void loadHistory(); });
onUnmounted(() => {
    clearTimeout(historyTimer);
    historyRequest++;
});

async function show(view) {
    current.value = view;
    notice.value = "";
    if (view === "intros" && !mediaLoaded.value) await loadMedia();
    if (view === "history") {
        if (rollingRange) historyRange.value = lastDay();
        await loadHistory();
    }
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

let previewRequest = 0;

async function loadPreviewSweepers(ids) {
    const request = ++previewRequest;
    stopPreview();
    previewSweepers.value = [];
    previewId.value = null;
    previewError.value = "";
    if (!ids?.length) return;
    try {
        const { rows } = await api(`media?playlist=${ids.join(",")}`);
        if (request !== previewRequest) return;
        previewSweepers.value = rows;
        previewId.value = previewSweepers.value[0]?.id ?? null;
    } catch (exception) {
        if (request === previewRequest) previewError.value = exception.message;
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

function changeMediaPerPage(size) {
    mediaPerPage.value = size;
    changePage(1);
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
        const result = await api(`media?search=${encodeURIComponent(search.value)}${unknownOnly.value ? "&unknown=1" : ""}&page=${target}&per_page=${mediaPerPage.value}`);
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

let moduleRefresh;

async function restartBroadcasting() {
    if (!window.confirm("Restart Liquidsoap broadcasting only? Audio is briefly interrupted; Icecast stays running.")) return;
    restarting.value = true;
    error.value = "";
    notice.value = "";
    try {
        const response = await fetch(restart.value.url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-API-CSRF": restart.value.csrf },
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.success === false) {
            throw new Error(result.message || "Broadcasting could not be restarted.");
        }
        notice.value = "Broadcasting restarted (Liquidsoap only). Icecast was not restarted. The module status updates within a minute.";
        clearTimeout(moduleRefresh);
        moduleRefresh = setTimeout(refreshModule, 20000);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        restarting.value = false;
    }
}

async function refreshModule() {
    try {
        moduleStatus.value = (await api("state")).module ?? moduleStatus.value;
    } catch {
        // The next page load shows the status.
    }
}

onUnmounted(() => clearTimeout(moduleRefresh));

onMounted(async () => {
    try {
        const state = await api("state");
        csrf.value = state.csrf ?? csrf.value;
        station.value = state.station;
        restart.value = state.restart ?? null;
        queueLength.value = state.queue_length ?? 2;
        moduleStatus.value = state.module ?? {};
        settings.value = state.settings;
        playlists.value = state.playlists;
        jinglePlaylists.value = state.jingle_playlists ?? [];
        stateLoaded.value = true;
        api("release").then((result) => { release.value = result; }, () => {});
        if (current.value === "intros") await loadMedia();
        if (current.value === "history") await loadHistory();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});
</script>

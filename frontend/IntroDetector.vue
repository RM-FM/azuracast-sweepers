<template>
    <div class="sweepers-detector">
        <div v-if="loadError" class="alert alert-danger" role="alert">{{ loadError }}</div>
        <div v-if="loading" class="d-flex justify-content-center py-3">
            <div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>

        <section v-else-if="info.installed === false" class="card mb-3" role="region" aria-labelledby="detector-missing-heading">
            <div class="card-header text-bg-primary">
                <h2 id="detector-missing-heading" class="card-title my-0">Intro Detector</h2>
            </div>
            <div class="card-body">
                <p class="card-text">The Intro Detector finds the intro end of each song, so sweepers fit automatically: where the vocals start or, in songs that open without vocals, where the musical intro ends. Songs are analyzed on rented GPUs at Runpod; each station enables it with a Runpod key and its own monthly budget (about USD 4 per 1,000 songs).</p>
                <template v-if="info.can_admin">
                    <p class="card-text">It is not installed on this server. To install it, run this in the plugin directory on the server:</p>
                    <pre class="mb-2"><code>sudo bash bin/setup.sh --detector</code></pre>
                </template>
                <p v-else class="card-text">It is not installed on this server. Ask the server administrator to install it.</p>
                <a href="https://github.com/RM-FM/azuracast-intro-detector" target="_blank" rel="noopener">More about the Intro Detector</a>
            </div>
        </section>

        <section v-else-if="status" class="card mb-3" role="region" aria-labelledby="detector-heading">
            <div class="card-header text-bg-primary">
                <h2 id="detector-heading" class="card-title my-0">Intro Detector</h2>
            </div>
            <div class="card-body">
                <nav class="nav nav-default nav-tabs mb-3" role="tablist" aria-label="Intro Detector">
                    <div v-for="[key, label] in tabs" :key="key" class="nav-item" role="presentation">
                        <button :id="'detector-tab-' + key" class="nav-link" :class="{ active: tab === key }" type="button" role="tab" :aria-selected="tab === key" :aria-controls="'detector-panel-' + key" @click="tab = key">{{ label }}</button>
                    </div>
                </nav>

                <form id="detector-panel-status" v-show="tab === 'status'" class="form sweepers-settings" role="tabpanel" aria-labelledby="detector-tab-status" @submit.prevent>
                    <div v-if="status.recovery" class="alert alert-warning" role="alert" data-detector-recovery>
                        <p class="mb-2">A stopped GPU worker may still hold results that were never copied back. Analysis is paused until it is removed.</p>
                        <button v-if="info.can_manage" type="button" class="btn btn-sm btn-danger" :disabled="busy" @click="retireWorker">Delete the worker and analyze its songs again</button>
                    </div>
                    <div v-else-if="status.blocked" class="alert alert-warning" role="status" data-detector-blocked>{{ blockedText(status.blocked) }}</div>

                    <div v-if="status.activity?.own" class="alert alert-info" role="status" data-detector-activity>
                        <div class="fw-semibold mb-1">Analysis running</div>
                        <div>{{ stepText(status.activity) }}</div>
                        <div v-if="detailText(status.activity)" class="small" data-detector-detail>{{ detailText(status.activity) }}</div>
                        <div class="progress my-2" role="progressbar" aria-label="Progress of this step" :aria-valuenow="progressPercent(status.activity) ?? undefined" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" :style="{ width: Math.max(progressPercent(status.activity) ?? 100, 3) + '%' }"></div>
                        </div>
                        <div class="small">{{ activityLine(status.activity, status.now) }} This station has {{ status.activity.station_songs }} of the {{ status.activity.songs }} songs in this run.</div>
                    </div>
                    <div v-else-if="status.activity && counts.waiting" class="alert alert-secondary" role="status" data-detector-queued>
                        Another station's analysis is running ({{ stepText(status.activity) }}). This station's waiting songs follow afterwards.
                    </div>

                    <fieldset class="form-group">
                        <legend>Status</legend>
                        <p class="card-text">Finds the intro end of new songs and writes it to AzuraCast, so sweepers fit automatically.</p>
                        <dl class="row g-3 mb-2 detector-summary">
                            <div v-for="[label, value] in summary" :key="label" class="col-6 col-md-4 col-xl-2">
                                <dt class="small text-muted fw-normal">{{ label }}</dt>
                                <dd class="fs-5 mb-0">{{ value }}</dd>
                            </div>
                        </dl>
                    </fieldset>

                    <fieldset v-if="!status.settings.enabled" class="form-group">
                        <legend>Analysis</legend>
                        <p class="card-text">The Intro Detector is off for this station. Switch it on in the Settings tab.</p>
                    </fieldset>
                    <template v-else>
                        <fieldset class="form-group" data-detector-analysis>

                            <p v-for="job in status.jobs" :key="job" class="card-text">{{ jobText(job) }}</p>
                            <ul class="list-group mb-0">
                                <li class="list-group-item d-flex flex-wrap align-items-center gap-2" data-detector-task="waiting">
                                    <div class="flex-fill">
                                        <div class="fw-semibold" :class="{ 'text-success': !counts.waiting && !status.activity }">{{ counts.waiting || status.activity ? "Waiting songs" : "Up to date" }}</div>
                                        <div class="small">{{ waitingText(counts.waiting, status.settings.auto_process, status.process_requested, status.activity) }}</div>
                                    </div>
                                    <button v-if="info.can_manage && counts.waiting && !status.process_requested && !status.activity" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="action('process', {})">Analyze now</button>
                                </li>
                                <li v-if="status.library_without_intro" class="list-group-item" data-detector-task="library">
                                    <div class="fw-semibold">Older songs</div>
                                    <div class="small">{{ libraryText(status.library_without_intro) }}</div>
                                    <div class="mt-2" data-detector-estimate>
                                        <dl class="row g-3 mb-2 detector-summary">
                                            <div v-for="[label, value] in estimateFigures(libraryEstimate, budgetLeft)" :key="label" class="col-6 col-md-4 col-xl-3">
                                                <dt class="small text-muted fw-normal">{{ label }}</dt>
                                                <dd class="fs-5 mb-0">{{ value }}</dd>
                                            </div>
                                        </dl>
                                        <div v-if="overBudget(libraryEstimate, budgetLeft)" class="alert alert-warning py-2 small mb-2" role="status" data-detector-over-budget>This is more than the budget left this month. The analysis pauses when the budget is reached and continues next month or after raising it.</div>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <button v-if="info.can_manage" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="startLibrary">Analyze them</button>
                                            <span class="small text-muted">Songs already analyzed for another station cost nothing.</span>
                                        </div>
                                    </div>
                                </li>                                <li v-if="counts.failed" class="list-group-item d-flex flex-wrap align-items-center gap-2" data-detector-task="failed">
                                    <div class="flex-fill">
                                        <div class="fw-semibold">Failed songs</div>
                                        <div class="small">{{ counts.failed }} songs could not be analyzed; the reasons are listed below.</div>
                                    </div>
                                    <button v-if="info.can_manage" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="action('retry', {})">Try again</button>
                                </li>
                            </ul>
                        </fieldset>

                        <fieldset class="form-group">
                            <legend>Recent Analysis Runs</legend>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead><tr><th>Started</th><th>Songs</th><th>Result</th></tr></thead>
                                    <tbody>
                                        <tr v-if="!status.runs.length"><td colspan="3" class="text-muted">No analysis run yet.</td></tr>
                                        <tr v-for="run in status.runs" :key="run.id">
                                            <td class="text-nowrap">{{ time(run.started_at) }}</td>
                                            <td>{{ run.songs }}</td>
                                            <td>{{ runResult(run.status, run.phase) }}<span v-if="run.error" class="small text-muted"> · {{ run.error }}</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </fieldset>

                        <fieldset class="form-group">
                            <legend>Failed songs</legend>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead><tr><th>File</th><th>Problem</th><th></th></tr></thead>
                                    <tbody>
                                        <tr v-if="!status.failures.length"><td colspan="3" class="text-muted">None.</td></tr>
                                        <tr v-for="failure in status.failures" :key="failure.media_id">
                                            <td class="text-break">{{ failure.path }}</td>
                                            <td class="small">{{ failure.error }}</td>
                                            <td class="text-end"><button v-if="info.can_manage" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="action('retry', { media_id: failure.media_id })">Retry</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </fieldset>
                    </template>
                </form>

                <div id="detector-panel-settings" v-show="tab === 'settings'" role="tabpanel" aria-labelledby="detector-tab-settings">
                    <form class="form sweepers-settings" @submit.prevent="saveSettings">
                        <fieldset :disabled="!info.can_manage || busy">
                            <fieldset class="form-group">
                                <legend>Detection</legend>
                                <div class="row g-3">
                                    <div class="form-group col-md-6">
                                        <div class="form-check form-switch">
                                            <input id="detector-enabled" v-model="form.enabled" class="form-check-input" type="checkbox" role="switch">
                                            <label class="form-check-label" for="detector-enabled">Use the Intro Detector for this station</label>
                                        </div>
                                        <div class="form-text">Detects song intros and stores them in the Intro Manager.</div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <div class="form-check form-switch">
                                            <input id="detector-auto" v-model="form.auto_process" class="form-check-input" type="checkbox" role="switch">
                                            <label class="form-check-label" for="detector-auto">Analyze new uploads automatically</label>
                                        </div>
                                        <div class="form-text">Otherwise new songs wait for "Analyze now".</div>
                                    </div>

                                    <div class="form-group col-md-6">
                                        <label class="form-label" for="detector-min-length">Minimum song length (seconds)</label>
                                        <input id="detector-min-length" v-model.number="form.min_song_seconds" class="form-control" type="number" min="0" max="3600" step="1" required>
                                        <div class="form-text">Shorter files, such as station IDs, are skipped. Jingle Mode playlists are always skipped.</div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label class="form-label" for="detector-max-length">Maximum song length (seconds)</label>
                                        <input id="detector-max-length" v-model.number="form.max_song_seconds" class="form-control" type="number" min="0" max="86400" step="1" required>
                                        <div class="form-text">Longer files, such as DJ mixes, are skipped. 0 means no maximum.</div>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset class="form-group">
                                <legend>Skip Playlists</legend>
                                <PlaylistPicker id="detector-excluded" v-model="form.exclude_playlists" title="Skipped playlists" :playlists="playlists" />
                                <div class="form-text">Songs from these playlists are never analyzed.</div>
                            </fieldset>

                            <fieldset class="form-group" data-detector-runpod>
                                <legend>Runpod</legend>
                                <div class="row g-3">
                                    <div class="form-group col-md-6">
                                        <label class="form-label" for="detector-key">Runpod API key</label>
                                        <input v-if="info.can_manage" id="detector-key" v-model="stationKey" class="form-control" type="password" autocomplete="off" :class="{ 'detector-key-stored': status.key }" :placeholder="status.key ? '••••••••••••••••••••••••••••••••' : 'Paste the Runpod API key'" :aria-description="status.key ? 'A key is stored. Enter a new key to replace it.' : undefined">
                                        <div class="form-text">Create a key with read/write access in the <a href="https://console.runpod.io/user/credentials" target="_blank" rel="noopener">Runpod console</a>. Several stations may use the same key. It is checked when you save and never shown again.</div>
                                        <button v-if="info.can_manage && status.key" type="button" class="btn btn-sm btn-danger mt-2" :disabled="busy" data-detector-remove-key @click="removeKey">Remove Key</button>
                                    </div>
                                    <div class="form-group col-sm-6 col-md-3">
                                        <label class="form-label" for="detector-budget">Monthly budget (USD)</label>
                                        <input id="detector-budget" v-model.number="form.budget_usd" class="form-control" type="number" min="0" step="1" required>
                                        <div class="form-text">Analysis pauses for this station when its spending this month reaches it. 0 means no limit.</div>
                                    </div>
                                    <div class="form-group col-sm-6 col-md-3">
                                        <label class="form-label" for="detector-idle">Idle timeout (minutes)</label>
                                        <input id="detector-idle" v-model.number="form.idle_timeout_minutes" class="form-control" type="number" min="1" max="60" step="1" required>
                                        <div class="form-text">How long the GPU worker waits for more songs before it shuts down. A shared worker uses the longest of its stations.</div>
                                    </div>
                                    <div class="form-group col-12">
                                        <div class="form-check form-switch">
                                            <input id="detector-eu-only" v-model="form.eu_only" class="form-check-input" type="checkbox" role="switch">
                                            <label class="form-check-label" for="detector-eu-only">Analyze this station's songs in the EU only</label>
                                        </div>
                                        <div class="form-text">Uses GPU workers in EU data centers only. Fewer GPUs are available there, so songs may wait longer.</div>
                                    </div>
                                </div>
                                <dl class="row g-3 mt-2 mb-0 detector-summary" data-detector-costs>
                                    <div class="col-6 col-lg-3" data-detector-spend>
                                        <dt class="small text-muted fw-normal">This station this month</dt>
                                        <dd class="fw-semibold mb-1">{{ money(status.spend_month_usd) }}<span v-if="status.limit_usd" class="fw-normal text-muted"> of {{ Number(status.limit_usd).toFixed(2) }}</span></dd>
                                        <div v-if="status.limit_usd" class="progress detector-meter" role="progressbar" aria-label="This station this month" :aria-valuenow="spendPercent" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar" :class="meterClass(spendPercent)" :style="{ width: spendPercent + '%' }"></div>
                                        </div>
                                        <div v-else class="small text-muted">No monthly budget</div>
                                    </div>
                                    <template v-if="status.key">
                                        <div class="col-6 col-lg-3" data-detector-key-use>
                                            <dt class="small text-muted fw-normal">Runpod account this month</dt>
                                            <dd class="fw-semibold mb-1">{{ money(status.key.spend_month_usd) }}</dd>
                                            <div class="small text-muted">{{ accountStationsText(status.key.stations) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3" data-detector-balance>
                                            <dt class="small text-muted fw-normal">Runpod balance</dt>
                                            <dd class="fw-semibold mb-1" :class="{ 'text-warning': lowBalance }">{{ money(status.key.balance_usd) }}</dd>
                                            <a class="small" href="https://console.runpod.io/user/billing" target="_blank" rel="noopener">Add funds</a>
                                        </div>
                                        <div class="col-6 col-lg-3" data-detector-worker>
                                            <dt class="small text-muted fw-normal">GPU worker</dt>
                                            <dd class="fw-semibold mb-1">{{ worker[0] }}</dd>
                                            <div class="small text-muted">{{ worker[1] }}</div>
                                        </div>
                                    </template>
                                </dl>
                                <div v-if="lowBalance" class="alert alert-warning py-2 small my-3" role="status" data-detector-low-balance>The Runpod balance is low. Runpod stops GPU work when it is used up.</div>
                            </fieldset>
                        </fieldset>
                        <div v-if="info.can_manage" class="buttons">
                            <button type="submit" class="btn btn-lg btn-primary" :disabled="busy || !dirty">Save Changes</button>
                        </div>
                    </form>

                </div>
            </div>
        </section>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import PlaylistPicker from "./PlaylistPicker.vue";
import {
    LOW_BALANCE_USD, accountStationsText, activityLine, blockedText, changedFields, detailText, meterClass, money, workerState, estimateFigures, jobText, libraryText,
    overBudget, progressPercent, queueCounts, runResult, stationForm, stepText, waitingText,
} from "./detector.js";
import { formatStationTime } from "./dates.js";
import { notifyError, notifySuccess } from "./toasts.js";

const props = defineProps({
    api: { type: Function, required: true },
    playlists: { type: Array, required: true },
    timezone: { type: String, default: undefined },
});
const REFRESH_MS = 15000;
// While an analysis runs or is about to start, the page follows it closely.
const REFRESH_ACTIVE_MS = 3000;
const REFRESH_CHECK_MS = 1000;
const tabs = [["status", "Status"], ["settings", "Settings"]];
const tab = ref("status");
const loading = ref(true);
const busy = ref(false);
const loadError = ref("");
const info = ref({});
const status = ref(null);
const form = ref({});
const stationKey = ref("");

let timer;
let lastRefresh = 0;
let refreshing = false;

const counts = computed(() => queueCounts(status.value?.queue));
const dirty = computed(() => status.value
    && (stationKey.value.trim() !== "" || Object.keys(changedFields(form.value, status.value.settings)).length > 0));
const budgetLeft = computed(() => status.value?.limit_usd ? Math.max(0, status.value.limit_usd - status.value.spend_month_usd) : null);
const libraryEstimate = computed(() => ({ songs: status.value.library_without_intro, estimated_usd: status.value.library_estimate_usd ?? null }));
// A run is going or about to start; refresh often so its progress appears promptly.
const activeSoon = computed(() => Boolean(status.value?.activity)
    || (counts.value.waiting > 0 && (status.value?.process_requested || status.value?.settings.auto_process) && !status.value?.blocked));
// Server time now, from the time of the last status and the local clock since, for the shutdown countdown.
const clock = ref(Date.now());
let statusReceivedAt = Date.now();
const serverNow = computed(() => (status.value?.now ?? 0) + (clock.value - statusReceivedAt) / 1000);
watch(() => status.value?.now, () => { statusReceivedAt = clock.value = Date.now(); });
const worker = computed(() => workerState(status.value?.worker, serverNow.value));
const spendPercent = computed(() => status.value?.limit_usd
    ? Math.min(100, Math.round(status.value.spend_month_usd / status.value.limit_usd * 100)) : 0);
const lowBalance = computed(() => (status.value?.key?.balance_usd ?? Infinity) < LOW_BALANCE_USD);
const summary = computed(() => [
    ["Detector", status.value.settings.enabled ? "On" : "Off"],
    ["GPU worker", worker.value[0]],
    ["Analyzed", status.value.analyzed ?? counts.value.done],
    ["Waiting", counts.value.waiting],
    ["Failed", counts.value.failed],
    ...(status.value.library_without_intro ? [["Older songs without intro", status.value.library_without_intro]] : []),
]);

function time(seconds) {
    return formatStationTime(seconds, props.timezone, { dateStyle: "short", timeStyle: "short" });
}

function apply(result) {
    info.value = { installed: result.installed, can_manage: result.can_manage, can_admin: result.can_admin };
    if (result.station) {
        const keepForm = status.value && dirty.value;
        status.value = result.station;
        if (!keepForm) form.value = stationForm(result.station.settings);
    }
}

async function load() {
    try {
        apply(await props.api("detector"));
        loadError.value = "";
    } catch (exception) {
        if (status.value) notifyError(exception.message);
        else loadError.value = exception.message;
    } finally {
        loading.value = false;
    }
}

async function run(task, message = "") {
    busy.value = true;
    try {
        await task();
        notifySuccess(message);
    } catch (exception) {
        notifyError(exception.message);
    } finally {
        busy.value = false;
    }
}

function saveSettings() {
    const changes = changedFields(form.value, status.value.settings);
    const key = stationKey.value.trim();
    return run(async () => {
        // The key goes first: if Runpod rejects it, nothing else is saved.
        if (key) {
            status.value = (await props.api("detector/key", { key })).station;
            stationKey.value = "";
        }
        if (Object.keys(changes).length) status.value = (await props.api("detector/settings", changes)).station;
        form.value = stationForm(status.value.settings);
    }, key ? "Saved. Key accepted." : "Saved.");
}

function removeKey() {
    if (!window.confirm("Remove the stored Runpod key from this station? GPU analysis for this station stops until a key is entered.")) return;
    return run(async () => {
        status.value = (await props.api("detector/key", { key: null })).station;
    }, "Runpod key removed.");
}

function action(name, data) {
    return run(async () => {
        const result = await props.api(`detector/${name}`, data);
        status.value = result.station ?? result;
    }, name === "process" ? "Analysis requested." : "Songs queued again.");
}



function startLibrary() {
    return run(async () => {
        status.value = (await props.api("detector/backfill", {})).station;
    }, "The songs were queued.");
}

function retireWorker() {
    if (!window.confirm("Delete the stopped GPU worker? Results it may hold are lost and its songs are analyzed again.")) return;
    return run(async () => {
        await props.api("detector/worker", { delete: true, force: true });
        await load();
    }, "GPU worker deleted.");
}

onMounted(async () => {
    await load();
    lastRefresh = Date.now();
    timer = setInterval(() => {
        clock.value = Date.now();
        const due = Date.now() - lastRefresh >= (activeSoon.value ? REFRESH_ACTIVE_MS : REFRESH_MS);
        if (due && !busy.value && !refreshing && info.value.installed && document.visibilityState === "visible") {
            lastRefresh = Date.now();
            refreshing = true;
            props.api("detector").then(apply, () => {}).finally(() => { refreshing = false; });
        }
    }, REFRESH_CHECK_MS);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <div class="sweepers-detector">
        <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-info" role="status">{{ notice }}</div>
        <div v-if="loading" class="d-flex justify-content-center py-3">
            <div class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>

        <section v-else-if="info.installed === false" class="card mb-3" role="region" aria-labelledby="detector-missing-heading">
            <div class="card-header text-bg-primary">
                <h2 id="detector-missing-heading" class="card-title my-0">Intro Detector</h2>
            </div>
            <div class="card-body">
                <p class="card-text">The Intro Detector finds the intro end of each song, so sweepers fit automatically: where the vocals start or, in songs that open without vocals, where the musical intro ends. Songs are analyzed on rented GPUs at Runpod; each station enables it and pays with its own Runpod key and budget (about US$4 per 1,000 songs).</p>
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
                        <button v-if="info.can_manage" type="button" class="btn btn-sm btn-warning" :disabled="busy" @click="retireWorker('worker')">Delete the worker and analyze its songs again</button>
                    </div>
                    <div v-else-if="status.blocked" class="alert alert-warning" role="status" data-detector-blocked>{{ blockedText(status.blocked) }}</div>

                    <div v-if="status.activity?.own" class="alert alert-info" role="status" data-detector-activity>
                        <div class="fw-semibold mb-1">Analysis running</div>
                        <div>{{ stepText(status.activity) }}</div>
                        <div class="progress my-2" role="progressbar" aria-label="Progress of this step" :aria-valuenow="progressPercent(status.activity) ?? undefined" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" :class="{ 'progress-bar-striped progress-bar-animated': progressPercent(status.activity) === null }" :style="{ width: (progressPercent(status.activity) ?? 100) + '%' }"></div>
                        </div>
                        <div class="small">{{ activityLine(status.activity, status.now) }} This station has {{ status.activity.station_songs }} of the {{ status.activity.songs }} songs in this run.</div>
                    </div>
                    <div v-else-if="status.activity && counts.waiting" class="alert alert-secondary" role="status" data-detector-queued>
                        Another station's analysis is running ({{ stepText(status.activity) }}). This station's waiting songs follow afterwards.
                    </div>

                    <fieldset class="form-group">
                        <legend>Status</legend>
                        <p class="card-text">Finds the intro end of new songs, so sweepers fit automatically: where the vocals start or, in songs that open without vocals, where the musical intro ends. In shadow mode it only records the proposed intros.</p>
                        <dl class="row g-3 mb-2 detector-summary">
                            <div v-for="[label, value] in summary" :key="label" class="col-6 col-md-4 col-xl-2">
                                <dt class="small text-muted fw-normal">{{ label }}</dt>
                                <dd class="fs-5 mb-0">{{ value }}</dd>
                            </div>
                        </dl>
                        <button type="button" class="btn btn-sm btn-secondary" :disabled="busy" @click="load"><Icon name="refresh" /><span>Refresh</span></button>
                    </fieldset>

                    <fieldset v-if="!status.settings.enabled" class="form-group">
                        <legend>Analysis</legend>
                        <p class="card-text">The Intro Detector is off for this station. Switch it on in the Settings tab.</p>
                    </fieldset>
                    <template v-else>
                        <fieldset class="form-group" data-detector-analysis>
                            <legend>Analyze Songs</legend>
                            <p class="form-text">Songs are analyzed on a rented GPU. This costs money, unless the same file was already analyzed for any station.</p>
                            <p v-for="job in status.jobs" :key="job" class="card-text">{{ jobText(job) }}</p>
                            <ul class="list-group mb-0">
                                <li class="list-group-item d-flex flex-wrap align-items-center gap-2" data-detector-task="waiting">
                                    <div class="flex-fill">
                                        <div class="fw-semibold">Waiting songs</div>
                                        <div class="small">{{ waitingText(counts.waiting, status.settings.auto_process, status.process_requested) }}</div>
                                    </div>
                                    <button v-if="info.can_manage && counts.waiting && !status.process_requested" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="action('process', {})">Analyze now</button>
                                </li>
                                <li class="list-group-item" data-detector-task="library">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <div class="flex-fill">
                                            <div class="fw-semibold">Older songs</div>
                                            <div class="small">{{ libraryText(status.library_without_intro) }}</div>
                                        </div>
                                        <button v-if="info.can_manage && status.library_without_intro && !estimate" type="button" class="btn btn-sm btn-secondary" :disabled="busy" @click="estimateLibrary">Show cost…</button>
                                    </div>
                                    <div v-if="estimate" class="mt-2" data-detector-estimate>
                                        <p class="small mb-2">{{ estimateText(estimate.songs, estimate.estimated_usd) }}</p>
                                        <div class="buttons">
                                            <button v-if="estimate.songs" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="startLibrary">Analyze them</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="estimate = null">Cancel</button>
                                        </div>
                                    </div>
                                </li>
                                <li v-if="counts.failed" class="list-group-item d-flex flex-wrap align-items-center gap-2" data-detector-task="failed">
                                    <div class="flex-fill">
                                        <div class="fw-semibold">Failed songs</div>
                                        <div class="small">{{ counts.failed }} songs could not be analyzed; the reasons are listed below.</div>
                                    </div>
                                    <button v-if="info.can_manage" type="button" class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="action('retry', {})">Try again</button>
                                </li>
                                <li v-if="planned && status.settings.mode === 'publish'" class="list-group-item d-flex flex-wrap align-items-center gap-2" data-detector-planned>
                                    <div class="flex-fill">
                                        <div class="fw-semibold">Results from shadow mode</div>
                                        <div class="small">{{ planned }} intros were found in shadow mode and are not in AzuraCast yet. Writing them needs no GPU.</div>
                                    </div>
                                    <button v-if="info.can_manage" type="button" class="btn btn-sm btn-primary" :disabled="busy || status.jobs.includes('republish')" @click="publish">Write them to AzuraCast</button>
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
                                            <td class="text-end"><button v-if="info.can_manage" type="button" class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="action('retry', { media_id: failure.media_id })">Retry</button></td>
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
                                        <div class="form-text">Existing songs are analyzed only when you start that on the Status tab.</div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <div class="form-check form-switch">
                                            <input id="detector-auto" v-model="form.auto_process" class="form-check-input" type="checkbox" role="switch">
                                            <label class="form-check-label" for="detector-auto">Analyze new uploads automatically</label>
                                        </div>
                                        <div class="form-text">Otherwise new songs wait for "Analyze now".</div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label class="form-label" for="detector-mode">Mode</label>
                                        <select id="detector-mode" v-model="form.mode" class="form-select">
                                            <option v-for="[value, label] in MODES" :key="value" :value="value">{{ label }}</option>
                                        </select>
                                        <div class="form-text">Intros entered by hand are never overwritten.</div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label class="form-label" for="detector-min-length">Minimum song length (seconds)</label>
                                        <input id="detector-min-length" v-model.number="form.min_song_seconds" class="form-control" type="number" min="0" max="3600" step="1" required>
                                        <div class="form-text">Shorter files, such as station IDs, are skipped. Jingle Mode playlists are always skipped.</div>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset class="form-group">
                                <legend>Skip Playlists</legend>
                                <PlaylistPicker id="detector-excluded" v-model="form.exclude_playlists" title="Skipped playlists" :playlists="playlists" />
                                <div class="form-text">Songs from these playlists are never analyzed.</div>
                            </fieldset>

                            <fieldset class="form-group">
                                <legend>GPU Billing</legend>
                                <div class="row g-3">
                                    <div class="form-group col-md-6">
                                        <div class="form-check">
                                            <input id="detector-billing-own" v-model="form.billing" class="form-check-input" type="radio" value="own">
                                            <label class="form-check-label" for="detector-billing-own">This station's own Runpod account</label>
                                        </div>
                                        <div class="form-check">
                                            <input id="detector-billing-shared" v-model="form.billing" class="form-check-input" type="radio" value="shared" :disabled="!status.shared_key_available">
                                            <label class="form-check-label" for="detector-billing-shared">Provided by the server administrator{{ status.shared_key_available ? "" : " (not offered to this station)" }}</label>
                                        </div>
                                    </div>
                                    <div v-if="form.billing === 'own'" class="form-group col-md-6">
                                        <label class="form-label" for="detector-budget">Monthly GPU budget (US$)</label>
                                        <input id="detector-budget" v-model.number="form.budget_usd" class="form-control" type="number" min="0" step="1" required>
                                        <div class="form-text">No GPU work starts once this month's estimated spend reaches the budget. 0 means no limit.</div>
                                    </div>
                                    <div v-if="form.billing === status.settings.billing" class="form-group col-md-6" data-detector-spend>
                                        <SpendMeter label="Spent this month" :spend="status.spend_month_usd" :limit="status.limit_usd" :note="spendNote(status)" />
                                    </div>
                                    <div v-if="info.can_manage && form.billing === 'own'" class="form-group col-12">
                                        <label class="form-label" for="detector-key">Runpod API key <span class="badge" :class="status.key ? 'text-bg-success' : 'text-bg-secondary'">{{ status.key ? "Stored" : "Missing" }}</span></label>
                                        <input id="detector-key" v-model="stationKey" class="form-control" type="password" autocomplete="off" :placeholder="status.key ? 'Enter a new key to replace the stored one' : 'Paste the Runpod API key'">
                                        <div class="form-text">Create a key with read/write access in the <a href="https://console.runpod.io/user/credentials" target="_blank" rel="noopener">Runpod console</a>. It is checked with Runpod, stored on the server and never shown again.</div>
                                        <div class="buttons mt-2">
                                            <button type="button" class="btn btn-sm btn-primary" :disabled="busy || !stationKey" @click="saveKey('key', stationKey)">Save Key</button>
                                            <button v-if="status.key" type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="removeKey('key')">Remove Key</button>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>
                        </fieldset>
                        <div v-if="info.can_manage" class="buttons">
                            <button type="submit" class="btn btn-lg btn-primary" :disabled="busy || !dirty">Save Changes</button>
                        </div>
                    </form>

                    <form v-if="info.can_admin && instance" class="form sweepers-settings mt-4" data-detector-admin @submit.prevent>
                    <fieldset class="form-group">
                        <legend id="detector-instance-heading">Shared Account</legend>
                        <p class="form-text">Only system administrators see this section.</p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6" data-detector-shared-spend>
                                <div class="mb-2">Runpod key <span class="badge" :class="instance.shared_key ? 'text-bg-success' : 'text-bg-secondary'">{{ instance.shared_key ? "Stored" : "Missing" }}</span></div>
                                <SpendMeter label="All stations this month" :spend="instance.shared_spend_month_usd" :limit="instance.settings.shared_budget_usd" />
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="form-group col-12">
                                <div class="form-check form-switch">
                                    <input id="detector-allow" v-model="allowance.shared_allowed" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="detector-allow">This station may use the shared account</label>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="form-label" for="detector-cap">Monthly limit for this station (US$)</label>
                                <input id="detector-cap" v-model.number="allowance.shared_cap_usd" class="form-control" type="number" min="0" step="1" :disabled="!allowance.shared_allowed">
                                <div class="form-text">0 means only the shared account's budget applies.</div>
                            </div>
                        </div>
                        <div class="buttons mt-2">
                            <button type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="saveAllowance(status.settings.id)">Save Station Access</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" :aria-expanded="showServer" aria-controls="detector-server-all" @click="showServer = !showServer">{{ showServer ? "Hide shared account settings" : "Shared account settings for all stations…" }}</button>
                        </div>

                        <div v-if="showServer" id="detector-server-all" class="border-top pt-3 mt-3">
                            <p class="form-text">Intro Detector {{ instance.version }} · {{ instance.analyses }} analyzed songs<span v-if="instance.cost_per_1000_songs_usd !== null"> · {{ money(instance.cost_per_1000_songs_usd) }} per 1,000 songs so far</span>.</p>
                            <div v-if="instance.shared_recovery" class="alert alert-warning" role="alert">
                                <p class="mb-2">A stopped GPU worker of the shared account may still hold results. Analysis on that account is paused until it is removed.</p>
                                <button type="button" class="btn btn-sm btn-warning" :disabled="busy" @click="retireWorker('instance/worker')">Delete the worker and analyze its songs again</button>
                            </div>
                            <div class="row g-3">
                                <div class="form-group col-md-4">
                                    <label class="form-label" for="detector-price">Highest GPU price (US$ per hour)</label>
                                    <input id="detector-price" v-model.number="instanceForm.max_price_per_hour" class="form-control" type="number" min="0.05" max="20" step="0.01" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label" for="detector-shared-budget">Monthly budget of the shared account (US$)</label>
                                    <input id="detector-shared-budget" v-model.number="instanceForm.shared_budget_usd" class="form-control" type="number" min="0" step="1" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label" for="detector-share">Shared analyses</label>
                                    <div class="form-check form-switch">
                                        <input id="detector-share" v-model="instanceForm.share_analyses" class="form-check-input" type="checkbox" role="switch">
                                        <label class="form-check-label" for="detector-share">Reuse analyses of identical files across stations</label>
                                    </div>
                                </div>
                            </div>
                            <div class="buttons mt-2 mb-3">
                                <button type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="saveInstance">Save Server Settings</button>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="detector-shared-key">Shared Runpod API key <span class="badge" :class="instance.shared_key ? 'text-bg-success' : 'text-bg-secondary'">{{ instance.shared_key ? "Stored" : "Missing" }}</span></label>
                                <input id="detector-shared-key" v-model="sharedKey" class="form-control" type="password" autocomplete="off" placeholder="Paste the Runpod API key to offer to stations">
                                <div class="buttons mt-2">
                                    <button type="button" class="btn btn-sm btn-primary" :disabled="busy || !sharedKey" @click="saveKey('instance/key', sharedKey)">Save Key</button>
                                    <button v-if="instance.shared_key" type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="removeKey('instance/key')">Remove Key</button>
                                </div>
                            </div>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm table-striped mb-0">
                                    <thead><tr><th>Station</th><th>Intro Detector</th><th>Shared account</th><th>Monthly limit</th><th>Spent this month</th></tr></thead>
                                    <tbody>
                                        <tr v-if="!instance.stations.length"><td colspan="5" class="text-muted">No station has used the Intro Detector yet.</td></tr>
                                        <tr v-for="row in instance.stations" :key="row.id">
                                            <td>{{ row.name ?? `Station ${row.id}` }}</td>
                                            <td>{{ row.enabled ? (row.billing === "shared" ? "On, shared account" : "On, own account") : "Off" }}</td>
                                            <td>{{ row.shared_allowed ? "Allowed" : "Not allowed" }}</td>
                                            <td>{{ row.shared_cap_usd ? money(row.shared_cap_usd) : "Budget only" }}</td>
                                            <td>{{ money(row.shared_spend_month_usd) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="form-text mt-2 mb-0">Station access is changed on each station's Intro Detector page.</p>
                        </div>
                    </fieldset>
                    </form>
                </div>
            </div>
        </section>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import Icon from "./Icon.vue";
import PlaylistPicker from "./PlaylistPicker.vue";
import SpendMeter from "./SpendMeter.vue";
import {
    MODES, activityLine, blockedText, changedFields, estimateText, jobText, libraryText, money, progressPercent, queueCounts, runResult,
    spendNote, stationForm, stepText, waitingText,
} from "./detector.js";
import { formatStationTime } from "./dates.js";

const props = defineProps({
    api: { type: Function, required: true },
    playlists: { type: Array, required: true },
    timezone: { type: String, default: undefined },
});
const REFRESH_MS = 30000;
const REFRESH_RUNNING_MS = 10000;
const tabs = [["status", "Status"], ["settings", "Settings"]];
const tab = ref("status");
const loading = ref(true);
const busy = ref(false);
const error = ref("");
const notice = ref("");
const info = ref({});
const status = ref(null);
const form = ref({});
const stationKey = ref("");
const estimate = ref(null);
const instance = ref(null);
const instanceForm = ref({});
const allowance = ref({ shared_allowed: false, shared_cap_usd: 0 });
const showServer = ref(false);
const sharedKey = ref("");
let timer;
let lastRefresh = 0;

const counts = computed(() => queueCounts(status.value?.queue));
const planned = computed(() => status.value?.publications?.planned ?? 0);
const dirty = computed(() => status.value && Object.keys(changedFields(form.value, status.value.settings)).length > 0);
const summary = computed(() => [
    ["Mode", status.value.settings.enabled ? (status.value.settings.mode === "publish" ? "Publishing" : "Shadow") : "Off"],
    ["Done", counts.value.done],
    ["Waiting", counts.value.waiting],
    ["Failed", counts.value.failed],
    ["Library without intro", status.value.library_without_intro],
    ["Written to AzuraCast", status.value.publications.written ?? 0],
]);

function time(seconds) {
    return formatStationTime(seconds, props.timezone, { dateStyle: "short", timeStyle: "short" });
}

function apply(result) {
    info.value = { installed: result.installed, can_manage: result.can_manage, can_admin: result.can_admin };
    if (result.station) {
        const keepForm = status.value && dirty.value;
        const saved = status.value?.settings;
        const keepAllowance = saved && (allowance.value.shared_allowed !== saved.shared_allowed
            || allowance.value.shared_cap_usd !== saved.shared_cap_usd);
        status.value = result.station;
        if (!keepForm) form.value = stationForm(result.station.settings);
        if (!keepAllowance) {
            allowance.value = {
                shared_allowed: result.station.settings.shared_allowed,
                shared_cap_usd: result.station.settings.shared_cap_usd,
            };
        }
    }
}

function applyInstance(result) {
    instance.value = result;
    instanceForm.value = { ...result.settings };
}

async function load() {
    error.value = "";
    try {
        apply(await props.api("detector"));
        if (info.value.installed && info.value.can_admin) applyInstance(await props.api("detector/instance"));
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
}

async function run(task, message = "") {
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        await task();
        notice.value = message;
    } catch (exception) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

function saveSettings() {
    const changes = changedFields(form.value, status.value.settings);
    return run(async () => {
        const result = await props.api("detector/settings", changes);
        status.value = result.station;
        form.value = stationForm(result.station.settings);
    }, "Saved.");
}

function saveKey(path, key) {
    return run(async () => {
        const result = await props.api(`detector/${path}`, { key: key.trim() });
        if (path === "key") {
            stationKey.value = "";
            status.value = result.station;
        } else {
            sharedKey.value = "";
            applyInstance(result);
            apply(await props.api("detector"));
        }
    }, "The Runpod key was accepted and stored.");
}

function removeKey(path) {
    if (!window.confirm("Remove the stored Runpod key? GPU analysis stops until a new key is entered.")) return;
    return run(async () => {
        const result = await props.api(`detector/${path}`, { key: null });
        if (path === "key") status.value = result.station;
        else applyInstance(result);
    }, "The Runpod key was removed.");
}

function action(name, data) {
    return run(async () => {
        const result = await props.api(`detector/${name}`, data);
        status.value = result.station ?? result;
    }, name === "process" ? "Analysis was requested; new songs are processed shortly." : "The songs were queued again.");
}

function publish() {
    return run(async () => {
        status.value = await props.api("detector/publish", {});
    }, "The stored intros are being written to AzuraCast.");
}

function estimateLibrary() {
    return run(async () => {
        estimate.value = await props.api("detector/backfill", { dry_run: true });
    });
}

function startLibrary() {
    return run(async () => {
        const result = await props.api("detector/backfill", {});
        status.value = result.station;
        estimate.value = null;
    }, "The library was queued. Its songs are analyzed after new uploads.");
}

function retireWorker(path) {
    if (!window.confirm("Delete the stopped GPU worker? Results it may hold are lost and its songs are analyzed again.")) return;
    return run(async () => {
        await props.api(`detector/${path}`, { delete: true, force: true });
        await load();
    }, "The GPU worker was deleted.");
}

function saveInstance() {
    return run(async () => applyInstance(await props.api("detector/instance/settings", instanceForm.value)), "Saved.");
}

function saveAllowance(stationId) {
    return run(async () => {
        applyInstance(await props.api("detector/instance/station", { station_id: stationId, ...allowance.value }));
        apply(await props.api("detector"));
    }, "Saved.");
}

onMounted(async () => {
    await load();
    lastRefresh = Date.now();
    timer = setInterval(() => {
        const due = Date.now() - lastRefresh >= (status.value?.activity ? REFRESH_RUNNING_MS : REFRESH_MS);
        if (due && !busy.value && info.value.installed && document.visibilityState === "visible") {
            lastRefresh = Date.now();
            props.api("detector").then(apply, () => {});
        }
    }, REFRESH_RUNNING_MS);
});
onUnmounted(() => clearInterval(timer));
</script>

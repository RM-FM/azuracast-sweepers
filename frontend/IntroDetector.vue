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
                <p class="card-text">The Intro Detector finds where the vocals start in each song, so sweepers fit automatically. Songs are analyzed on rented GPUs at Runpod; each station enables it and pays with its own Runpod key and budget (about US$4 per 1,000 songs).</p>
                <template v-if="info.can_admin">
                    <p class="card-text">It is not installed on this server. To install it, run this in the plugin directory on the server:</p>
                    <pre class="mb-2"><code>sudo bash bin/setup.sh --detector</code></pre>
                </template>
                <p v-else class="card-text">It is not installed on this server. Ask the server administrator to install it.</p>
                <a href="https://github.com/RM-FM/azuracast-intro-detector" target="_blank" rel="noopener">More about the Intro Detector</a>
            </div>
        </section>

        <template v-else-if="status">
            <section class="card mb-3" role="region" aria-labelledby="detector-heading">
                <div class="card-header text-bg-primary d-flex align-items-center">
                    <h2 id="detector-heading" class="card-title flex-fill my-0">Intro Detector</h2>
                    <button type="button" class="btn btn-sm btn-light" :disabled="busy" @click="load"><Icon name="refresh" /><span>Refresh</span></button>
                </div>
                <div class="card-body">
                    <p class="card-text">Finds where the vocals start in new songs and stores it as the intro end, so sweepers fit automatically. In shadow mode it only records proposed intros.</p>
                    <div v-if="status.recovery" class="alert alert-warning" role="alert" data-detector-recovery>
                        <p class="mb-2">A stopped GPU worker may still hold results that were never copied back. Analysis is paused until it is removed.</p>
                        <button v-if="info.can_manage" type="button" class="btn btn-sm btn-warning" :disabled="busy" @click="retireWorker('worker', true)">Delete the worker and analyze its songs again</button>
                    </div>
                    <div v-else-if="status.blocked" class="alert alert-warning" role="status" data-detector-blocked>{{ blockedText(status.blocked) }}</div>

                    <div class="row g-3 mb-3">
                        <div v-for="[label, value] in summary" :key="label" class="col-6 col-md-2">
                            <div class="small text-muted">{{ label }}</div>
                            <div class="fs-5">{{ value }}</div>
                        </div>
                    </div>
                    <p class="card-text small text-muted mb-0">GPU cost: {{ spendLine(status.spend_month_usd, status.limit_usd) }}. {{ workerText(status.worker) }}</p>
                </div>
            </section>

            <section class="card mb-3" role="region" aria-labelledby="detector-settings-heading">
                <div class="card-header text-bg-primary">
                    <h2 id="detector-settings-heading" class="card-title my-0">Station Settings</h2>
                </div>
                <form class="form" @submit.prevent="saveSettings">
                    <fieldset class="card-body" :disabled="!info.can_manage || busy">
                        <div class="row g-3">
                            <div class="form-group col-md-6">
                                <div class="form-check form-switch">
                                    <input id="detector-enabled" v-model="form.enabled" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="detector-enabled">Use the Intro Detector for this station</label>
                                </div>
                                <div class="form-text">Existing songs are not analyzed until you start that below.</div>
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
                            <fieldset class="form-group col-12">
                                <legend class="form-label">Skip these playlists</legend>
                                <PlaylistPicker id="detector-excluded" v-model="form.exclude_playlists" title="Skipped playlists" :playlists="playlists" />
                            </fieldset>
                            <fieldset class="form-group col-12">
                                <legend class="form-label">GPU billing</legend>
                                <div class="form-check">
                                    <input id="detector-billing-own" v-model="form.billing" class="form-check-input" type="radio" value="own">
                                    <label class="form-check-label" for="detector-billing-own">This station's own Runpod account</label>
                                </div>
                                <div class="form-check">
                                    <input id="detector-billing-shared" v-model="form.billing" class="form-check-input" type="radio" value="shared" :disabled="!status.shared_key_available">
                                    <label class="form-check-label" for="detector-billing-shared">The server's Runpod account{{ status.shared_key_available ? "" : " (not offered to this station)" }}</label>
                                </div>
                            </fieldset>
                            <div v-if="form.billing === 'own'" class="form-group col-md-6">
                                <label class="form-label" for="detector-budget">Monthly GPU budget (US$)</label>
                                <input id="detector-budget" v-model.number="form.budget_usd" class="form-control" type="number" min="0" step="1" required>
                                <div class="form-text">No GPU work starts once this month's estimated spend reaches the budget. 0 means no limit.</div>
                            </div>
                        </div>
                    </fieldset>
                    <div v-if="info.can_manage" class="card-body buttons">
                        <button type="submit" class="btn btn-primary" :disabled="busy || !dirty">Save Changes</button>
                    </div>
                </form>
                <div v-if="info.can_manage && form.billing === 'own'" class="card-body border-top">
                    <form class="row g-2 align-items-end" @submit.prevent="saveKey('key', stationKey)">
                        <div class="col-md-8">
                            <label class="form-label" for="detector-key">Runpod API key <span class="badge" :class="status.key ? 'text-bg-success' : 'text-bg-secondary'">{{ status.key ? "Stored" : "Missing" }}</span></label>
                            <input id="detector-key" v-model="stationKey" class="form-control" type="password" autocomplete="off" :placeholder="status.key ? 'Enter a new key to replace the stored one' : 'Paste the Runpod API key'">
                            <div class="form-text">Create a key with read/write access in the <a href="https://console.runpod.io/user/credentials" target="_blank" rel="noopener">Runpod console</a>. It is checked with Runpod, stored on the server and never shown again.</div>
                        </div>
                        <div class="col-md-4 buttons">
                            <button type="submit" class="btn btn-primary" :disabled="busy || !stationKey">Save Key</button>
                            <button v-if="status.key" type="button" class="btn btn-outline-danger" :disabled="busy" @click="removeKey('key')">Remove</button>
                        </div>
                    </form>
                </div>
            </section>

            <section v-if="status.settings.enabled" class="card mb-3" role="region" aria-labelledby="detector-work-heading">
                <div class="card-header text-bg-primary">
                    <h2 id="detector-work-heading" class="card-title my-0">Analysis</h2>
                </div>
                <div class="card-body">
                    <div v-if="planned && status.settings.mode === 'publish'" class="alert alert-info" role="status" data-detector-planned>
                        <p class="mb-2">{{ planned }} intros were found while this station was in shadow mode and are not in AzuraCast yet.</p>
                        <button v-if="info.can_manage" type="button" class="btn btn-sm btn-primary" :disabled="busy || status.jobs.includes('republish')" @click="publish">Write them to AzuraCast</button>
                    </div>
                    <p v-if="status.jobs.length" class="card-text">Working on it: {{ status.jobs.join(", ") }}.</p>
                    <div v-if="info.can_manage" class="buttons mb-3">
                        <button type="button" class="btn btn-primary" :disabled="busy || status.process_requested" @click="action('process', {})">{{ status.process_requested ? "Analysis requested" : "Analyze now" }}</button>
                        <button type="button" class="btn btn-secondary" :disabled="busy" @click="estimateLibrary">Analyze existing library…</button>
                        <button v-if="counts.failed" type="button" class="btn btn-outline-secondary" :disabled="busy" @click="action('retry', {})">Retry all failed</button>
                    </div>
                    <div v-if="estimate" class="alert alert-secondary" role="status" data-detector-estimate>
                        <p class="mb-2">{{ estimateText(estimate.songs, estimate.estimated_usd) }}</p>
                        <div class="buttons">
                            <button v-if="estimate.songs" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="startLibrary">Queue them</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="estimate = null">Cancel</button>
                        </div>
                    </div>

                    <h3 class="h5">Recent batches</h3>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Started</th><th>Songs</th><th>Result</th></tr></thead>
                            <tbody>
                                <tr v-if="!status.runs.length"><td colspan="3" class="text-muted">No batch yet.</td></tr>
                                <tr v-for="run in status.runs" :key="run.id">
                                    <td>{{ time(run.started_at) }}</td>
                                    <td>{{ run.songs }}</td>
                                    <td>{{ run.status }}<span v-if="run.error" class="small text-muted"> · {{ run.error }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="h5">Failed songs</h3>
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
                </div>
            </section>

            <section v-if="info.can_admin && instance" class="card mb-3" role="region" aria-labelledby="detector-instance-heading">
                <div class="card-header text-bg-primary">
                    <h2 id="detector-instance-heading" class="card-title my-0">Server-wide Settings</h2>
                </div>
                <div class="card-body">
                    <p class="card-text small text-muted">Only system administrators see this section. Intro Detector {{ instance.version }} · {{ instance.analyses }} analyzed songs<span v-if="instance.cost_per_1000_songs_usd !== null"> · {{ money(instance.cost_per_1000_songs_usd) }} per 1,000 songs so far</span>.</p>
                    <div v-if="instance.shared_recovery" class="alert alert-warning" role="alert">
                        <p class="mb-2">A stopped GPU worker of the server's Runpod account may still hold results. Analysis on that account is paused until it is removed.</p>
                        <button type="button" class="btn btn-sm btn-warning" :disabled="busy" @click="retireWorker('instance/worker', true)">Delete the worker and analyze its songs again</button>
                    </div>
                    <form class="row g-3 mb-3" @submit.prevent="saveInstance">
                        <div class="form-group col-md-4">
                            <label class="form-label" for="detector-price">Highest GPU price (US$ per hour)</label>
                            <input id="detector-price" v-model.number="instanceForm.max_price_per_hour" class="form-control" type="number" min="0.05" max="20" step="0.01" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="form-label" for="detector-shared-budget">Server account: monthly budget (US$)</label>
                            <input id="detector-shared-budget" v-model.number="instanceForm.shared_budget_usd" class="form-control" type="number" min="0" step="1" required>
                        </div>
                        <div class="form-group col-md-4">
                            <div class="form-check form-switch mt-md-4">
                                <input id="detector-share" v-model="instanceForm.share_analyses" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="detector-share">Reuse analyses of identical files across stations</label>
                            </div>
                        </div>
                        <div class="col-12 buttons"><button type="submit" class="btn btn-primary" :disabled="busy">Save Server Settings</button></div>
                    </form>
                    <form class="row g-2 align-items-end mb-3" @submit.prevent="saveKey('instance/key', sharedKey)">
                        <div class="col-md-8">
                            <label class="form-label" for="detector-shared-key">Server Runpod API key <span class="badge" :class="instance.shared_key ? 'text-bg-success' : 'text-bg-secondary'">{{ instance.shared_key ? "Stored" : "Missing" }}</span></label>
                            <input id="detector-shared-key" v-model="sharedKey" class="form-control" type="password" autocomplete="off" placeholder="Paste a Runpod API key to offer to stations">
                            <div class="form-text">Stations allowed below can use this account instead of their own. {{ spendLine(instance.shared_spend_month_usd, instanceForm.shared_budget_usd) }}.</div>
                        </div>
                        <div class="col-md-4 buttons">
                            <button type="submit" class="btn btn-primary" :disabled="busy || !sharedKey">Save Key</button>
                            <button v-if="instance.shared_key" type="button" class="btn btn-outline-danger" :disabled="busy" @click="removeKey('instance/key')">Remove</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Station</th><th>Detector</th><th>May use the server account</th><th>Monthly limit (US$, 0 = budget only)</th><th>Spent this month</th><th></th></tr></thead>
                            <tbody>
                                <tr v-if="!instance.stations.length"><td colspan="6" class="text-muted">No station has used the Intro Detector yet.</td></tr>
                                <tr v-for="row in instance.stations" :key="row.id">
                                    <td>{{ row.name ?? `Station ${row.id}` }}</td>
                                    <td>{{ row.enabled ? (row.billing === "shared" ? "On, server account" : "On, own account") : "Off" }}</td>
                                    <td><input v-model="allowances[row.id].shared_allowed" class="form-check-input" type="checkbox" :aria-label="`Allow ${row.name ?? row.id}`"></td>
                                    <td><input v-model.number="allowances[row.id].shared_cap_usd" class="form-control form-control-sm" type="number" min="0" step="1" :aria-label="`Limit for ${row.name ?? row.id}`"></td>
                                    <td>{{ money(row.shared_spend_month_usd) }}</td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" :disabled="busy" @click="saveAllowance(row.id)">Save</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import Icon from "./Icon.vue";
import PlaylistPicker from "./PlaylistPicker.vue";
import { MODES, blockedText, changedFields, estimateText, money, queueCounts, spendLine, stationForm, workerText } from "./detector.js";
import { formatStationTime } from "./dates.js";

const props = defineProps({
    api: { type: Function, required: true },
    playlists: { type: Array, required: true },
    timezone: { type: String, default: undefined },
});
const REFRESH_MS = 30000;
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
const allowances = ref({});
const sharedKey = ref("");
let timer;

const counts = computed(() => queueCounts(status.value?.queue));
const planned = computed(() => status.value?.publications?.planned ?? 0);
const dirty = computed(() => status.value && Object.keys(changedFields(form.value, status.value.settings)).length > 0);
const summary = computed(() => [
    ["Status", status.value.settings.enabled ? (status.value.settings.mode === "publish" ? "Publishing" : "Shadow") : "Off"],
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
        status.value = result.station;
        if (!keepForm) form.value = stationForm(result.station.settings);
    }
}

function applyInstance(result) {
    instance.value = result;
    instanceForm.value = { ...result.settings };
    allowances.value = Object.fromEntries(result.stations.map((row) =>
        [row.id, { shared_allowed: row.shared_allowed, shared_cap_usd: row.shared_cap_usd }]));
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

function retireWorker(path, force) {
    if (!window.confirm("Delete the stopped GPU worker? Results it may hold are lost and its songs are analyzed again.")) return;
    return run(async () => {
        await props.api(`detector/${path}`, { delete: true, force });
        await load();
    }, "The GPU worker was deleted.");
}

function saveInstance() {
    return run(async () => applyInstance(await props.api("detector/instance/settings", instanceForm.value)), "Saved.");
}

function saveAllowance(stationId) {
    return run(async () => {
        applyInstance(await props.api("detector/instance/station", { station_id: stationId, ...allowances.value[stationId] }));
        apply(await props.api("detector"));
    }, "Saved.");
}

onMounted(async () => {
    await load();
    timer = setInterval(() => {
        if (!busy.value && info.value.installed && document.visibilityState === "visible") {
            props.api("detector").then(apply, () => {});
        }
    }, REFRESH_MS);
});
onUnmounted(() => clearInterval(timer));
</script>

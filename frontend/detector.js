export const MODES = [
    ["shadow", "Shadow: record proposed intros, change nothing"],
    ["publish", "Publish: write intros to AzuraCast"],
];

const STATION_FIELDS = ["enabled", "mode", "auto_process", "min_song_seconds", "exclude_playlists", "billing", "budget_usd"];

const BLOCKED = {
    "no Runpod key": "Add a Runpod API key to start analyzing songs.",
    "monthly budget reached": "This month's GPU budget is used up. Analysis continues next month or after raising the budget.",
    "station limit on the instance key reached": "This station's monthly limit on the instance key is used up.",
    "waiting for GPU retry": "The last GPU batch could not start. It is retried automatically in a few minutes.",
};

export function stationForm(settings) {
    return Object.fromEntries(STATION_FIELDS.map((field) => [
        field,
        Array.isArray(settings[field]) ? [...settings[field]] : settings[field],
    ]));
}

export function changedFields(form, saved) {
    return Object.fromEntries(STATION_FIELDS
        .filter((field) => JSON.stringify(form[field]) !== JSON.stringify(saved[field]))
        .map((field) => [field, form[field]]));
}

export function money(value) {
    return value === null || value === undefined ? "–" : `US$${Number(value).toFixed(2)}`;
}

export function spendLine(spend, limit) {
    return `${money(spend)} of ${limit ? money(limit) : "no limit"} this month`;
}

export function stationCost(status) {
    const shared = status.settings.account === "shared";
    const limit = status.limit_usd ? money(status.limit_usd) : shared ? "the server account's budget" : "no limit";
    return `${money(status.spend_month_usd)} of ${limit} this month${shared ? " on the server's Runpod account" : ""}`;
}

export function blockedText(reason) {
    return reason ? BLOCKED[reason] ?? reason : "";
}

export function workerText(worker) {
    if (!worker) return "No GPU worker is running.";
    const state = worker.idle_since ? "idle, deleted automatically after 10 minutes" : "working";
    return `GPU worker ${worker.pod_id} (${worker.gpu_id ?? "GPU"}, ${money(worker.price)}/h): ${state}.`;
}

export function queueCounts(queue = {}) {
    const count = (status) => queue[status] ?? 0;
    return {
        done: count("done"),
        waiting: count("pending"),
        failed: count("failed"),
        library: count("known"),
        skipped: count("excluded"),
    };
}

export function estimateText(songs, usd) {
    if (!songs) return "Every older song already has an intro or a result.";
    const cost = usd === null || usd === undefined ? "" : ` and costs about ${money(usd)} of GPU time`;
    return `Analyzing ${songs} older songs takes a while${cost}. Songs analyzed for another station are reused at no cost; new uploads go first.`;
}

export function waitingText(count, automatic, requested) {
    if (requested) return "The analysis starts shortly.";
    if (!count) return `No songs are waiting. ${automatic ? "New uploads are analyzed automatically." : "New uploads wait here until you start the analysis."}`;
    return automatic
        ? `${count} songs are waiting. They are analyzed automatically within a few minutes, or right away with Analyze now.`
        : `${count} songs are waiting until you start the analysis.`;
}

export function libraryText(count) {
    return count
        ? `${count} songs that were already in the library have no intro yet. They are only analyzed if you start it.`
        : "Every song that was already in the library has an intro or a result.";
}

const JOBS = { sync: "Reading the station's library…", republish: "Writing intros to AzuraCast…" };
const RESULTS = { complete: "Done", partial: "Partly done", failed: "Failed", running: "Running", interrupted: "Interrupted" };
const STEPS = {
    download: "Downloading songs from AzuraCast",
    worker: "Starting a GPU worker",
    upload: "Uploading songs to the GPU worker",
    analyze: "Analyzing songs",
    copy: "Copying results back",
    save: "Saving intros",
};
// Renting a worker, pulling its image and loading models usually takes about this long.
const WORKER_START_SECONDS = 300;

export function jobText(job) {
    return JOBS[job] ?? job;
}

export function runResult(status, step = null) {
    const result = RESULTS[status] ?? status;
    const name = STEPS[step];
    return status === "failed" && name ? `${result} while ${name[0].toLowerCase()}${name.slice(1)}` : result;
}

export function stepText(activity) {
    const name = STEPS[activity.step] ?? activity.step;
    if (!activity.total) return activity.step === "worker" ? `${name} (usually about 5 minutes)` : name;
    const unit = activity.step === "analyze" ? " parts" : "";
    return `${name}: ${activity.done} of ${activity.total}${unit}`;
}

export function progressPercent(activity) {
    return activity.total ? Math.min(100, Math.round(activity.done / activity.total * 100)) : null;
}

export function duration(seconds) {
    if (seconds < 60) return "less than a minute";
    const minutes = Math.round(seconds / 60);
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/** Seconds left in the current step from its pace so far, or null when unknown. */
export function stepRemaining(activity, now) {
    const elapsed = Math.max(0, now - activity.step_started_at);
    if (activity.step === "worker") return Math.max(0, WORKER_START_SECONDS - elapsed);
    if (!activity.total || !activity.done) return null;
    return elapsed / activity.done * (activity.total - activity.done);
}

export function activityLine(activity, now) {
    const remaining = stepRemaining(activity, now);
    const left = remaining === null ? "" : ` · about ${duration(remaining)} left in this step`;
    return `Running for ${duration(Math.max(0, now - activity.started_at))}${left}.`;
}

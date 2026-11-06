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
const RESULTS = { complete: "Done", partial: "Partly done", failed: "Failed", running: "Running" };

export function jobText(job) {
    return JOBS[job] ?? job;
}

export function runResult(status) {
    return RESULTS[status] ?? status;
}

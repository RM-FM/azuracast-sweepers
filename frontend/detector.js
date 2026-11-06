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
    if (!songs) return "Every song of the library already has an intro or a result.";
    const cost = usd === null || usd === undefined ? "" : `, about ${money(usd)} of GPU time`;
    return `${songs} songs without an intro${cost}. Songs analyzed for another station are reused at no cost.`;
}

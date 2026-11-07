
const STATION_FIELDS = ["enabled", "auto_process", "min_song_seconds", "max_song_seconds", "exclude_playlists", "budget_usd"];

const BLOCKED = {
    "no Runpod key": "Add a Runpod API key to start analyzing songs.",
    "monthly budget reached": "This month's GPU budget is used up. Analysis continues next month or after raising the budget.",
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

// Runpod stops workers when the prepaid balance runs out; warn well before that.
export const LOW_BALANCE_USD = 5;

export function spendNote(status) {
    return status.limit_usd ? "" : "No monthly budget is set for this station.";
}

export function keyStationsText(stations) {
    return stations > 1 ? `${stations} stations use this Runpod account and share its GPU worker.` : "Only this station uses this Runpod account.";
}

export function balanceText(balance) {
    if (balance === null || balance === undefined) return "Runpod balance not checked yet.";
    return balance < LOW_BALANCE_USD
        ? `Runpod balance: ${money(balance)}. Add funds soon; Runpod stops GPU work when the balance is used up.`
        : `Runpod balance: ${money(balance)}.`;
}

export function blockedText(reason) {
    return reason ? BLOCKED[reason] ?? reason : "";
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

/** Figures of a library estimate; budget left is null without a monthly budget. */
export function estimateFigures(estimate, budgetLeft) {
    return [
        ["Songs", String(estimate.songs)],
        ["Estimated cost", estimate.estimated_usd === null ? "Known after the first run" : money(estimate.estimated_usd)],
        ...(budgetLeft === null ? [] : [["Budget left this month", money(budgetLeft)]]),
    ];
}

export function overBudget(estimate, budgetLeft) {
    return budgetLeft !== null && estimate.estimated_usd !== null && estimate.estimated_usd > budgetLeft;
}

export function waitingText(count, automatic, requested, activity = null) {
    if (activity?.own) {
        const running = Math.min(count, activity.station_songs ?? 0);
        const later = count - running;
        return `${running} songs are being analyzed now.${later ? ` ${later} more follow in the next run.` : ""}`;
    }
    if (activity && count) return `${count} songs are waiting until the current analysis of another station has finished.`;
    if (requested) return "The analysis starts within a minute or two.";
    if (!count) return `No songs are waiting. ${automatic ? "New uploads are analyzed automatically." : "New uploads wait here until you start the analysis."}`;
    return automatic
        ? `${count} songs are waiting. They are analyzed automatically within a few minutes, or right away with Analyze now.`
        : `${count} songs are waiting until you start the analysis.`;
}

export function libraryText(count) {
    return count
        ? "Songs that were in the library before the detector was switched on have no intro yet. They are only analyzed if you start it."
        : "Every song that was already in the library has an intro or a result.";
}

const JOBS = { sync: "Reading the station's library…", republish: "Writing intros to AzuraCast…", publish: "Writing intros to AzuraCast…" };
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
    const unit = activity.step === "analyze" ? " parts done" : "";
    return `${name}: ${Math.floor(activity.done)} of ${activity.total}${unit}`;
}

// Pipeline stages of the GPU worker, in their order.
const STAGES = {
    "demucs-melband-whisper": "separating vocals",
    matrix: "comparing vocal detectors",
    "musical-timing": "measuring the musical intro",
    "panns-levels": "measuring sound levels",
    qwen: "transcribing lyrics",
    "whisper-auto-language": "detecting the language",
    "smad-singing-classifier": "detecting singing",
    evaluate: "deciding the intro ends",
};
const COUNTRIES = {
    AT: "Austria", BE: "Belgium", CZ: "Czechia", DE: "Germany", ES: "Spain", FI: "Finland", FR: "France",
    IS: "Iceland", IT: "Italy", NL: "Netherlands", NO: "Norway", PL: "Poland", RO: "Romania", SE: "Sweden",
};
const REGIONS = { US: "USA", CA: "Canada", AP: "Asia-Pacific", OC: "Oceania", SA: "South America" };

/** Data center with its country, e.g. "EU-RO-1 (Romania)". */
export function place(location) {
    const [region, country] = String(location ?? "").split("-");
    const name = region === "EU" ? COUNTRIES[country] : REGIONS[region];
    return name ? `${location} (${name})` : String(location ?? "");
}

function gpuName(gpu) {
    return String(gpu ?? "a GPU").replace(/^NVIDIA (GeForce )?/, "");
}

/** What the current step is doing right now, or "" when there is nothing to add. */
export function detailText(activity) {
    const detail = activity.detail ?? {};
    const rented = () => `${gpuName(detail.gpu)} in ${place(detail.location)} at ${money(detail.price)}/hour`;
    switch (`${activity.step}:${detail.phase ?? ""}`) {
    case "worker:reuse": return "Using the GPU worker that is already running.";
    case "worker:search": return `Renting ${rented()}…`;
    case "worker:no_stock": return `No suitable GPU is free at up to ${money(detail.max_price)}/hour right now; checking again (attempt ${detail.attempt}).`;
    case "worker:boot": return `${rented()} is starting and loading the analysis software.`;
    case "analyze:prepare": return `Checking the GPU and loading the models for ${detail.lanes ?? 1} parallel lanes.`;
    case "analyze:run": {
        const now = (detail.stages ?? []).map((stage) => STAGES[stage] ?? stage);
        const failed = detail.failed ? ` ${detail.failed} parts failed; their songs are retried later.` : "";
        return (now.length ? `Now: ${now.join(", ")}.` : "") + failed;
    }
    default:
        return activity.step === "upload" && detail.songs ? `${detail.songs} songs, ${detail.megabytes} MB.` : "";
    }
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

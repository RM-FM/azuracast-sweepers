import assert from "node:assert/strict";
import test from "node:test";
import {
    accountStationsText, activityLine, blockedText, changedFields, countdown, detailText, duration, place, estimateFigures, jobText, libraryText,
    meterClass, money, workerState,
    overBudget, progressPercent, queueCounts, runResult, stationForm, stepRemaining, stepText, waitingText,
} from "../frontend/detector.js";

test("detector form sends only changed station fields", () => {
    const saved = { enabled: false, auto_process: true, min_song_seconds: 60, exclude_playlists: [3],
        budget_usd: 10, id: 1 };
    const form = stationForm(saved);
    assert.equal(form.id, undefined, "Only station fields are editable");
    form.exclude_playlists.push(4);
    assert.deepEqual(saved.exclude_playlists, [3], "Editing the form leaves the saved settings alone");
    form.enabled = true;
    assert.deepEqual(changedFields(form, saved), { enabled: true, exclude_playlists: [3, 4] });
    assert.deepEqual(changedFields(stationForm(saved), saved), {});
});

test("detector texts explain money, blocks and backfill estimates", () => {
    assert.equal(money(null), "–");
    assert.equal(money(3.456), "USD 3.46");
    assert.match(blockedText("no Runpod key"), /Add a Runpod API key/);
    assert.match(blockedText("disk almost full"), /at least 1 GB is free/);
    assert.equal(blockedText("something new"), "something new");
    assert.equal(blockedText(null), "");
    assert.deepEqual(queueCounts({ done: 2, pending: 1, known: 7 }), { done: 2, waiting: 1, failed: 0, library: 7, skipped: 0 });
    assert.deepEqual(estimateFigures({ songs: 1000, estimated_usd: 4 }, 9.5),
        [["Songs", "1000"], ["Estimated cost", "USD 4.00"], ["Budget left this month", "USD 9.50"]]);
    assert.deepEqual(estimateFigures({ songs: 3, estimated_usd: null }, null), [["Songs", "3"], ["Estimated cost", "Known after the first run"]]);
    assert.equal(overBudget({ estimated_usd: 4 }, 9.5), false);
    assert.equal(overBudget({ estimated_usd: 12 }, 9.5), true);
    assert.equal(overBudget({ estimated_usd: 12 }, null), false, "No budget, no limit");
});

test("cost figures name the account's stations and count down to an idle worker's shutdown", () => {
    assert.equal(accountStationsText(1), "Only this station");
    assert.equal(accountStationsText(4), "4 stations share it");
    assert.deepEqual([meterClass(50), meterClass(80), meterClass(100)], ["bg-success", "bg-warning", "bg-danger"]);
    assert.equal(countdown(372.2), "6:13");
    assert.equal(countdown(-5), "0:00");
    const worker = { gpu_id: "NVIDIA GeForce RTX 4090", price: 0.89, shutdown_at: null };
    assert.deepEqual(workerState(null, 1000), ["Down", "Started when songs need analysis"]);
    assert.deepEqual(workerState(worker, 1000), ["Up", "RTX 4090 · USD 0.89/hour"]);
    assert.deepEqual(workerState({ ...worker, gpu_id: null }, 1000), ["Up", "USD 0.89/hour"]);
    assert.deepEqual(workerState({ ...worker, shutdown_at: 1372 }, 1000), ["Idle", "Shuts down in 6:12"]);
    assert.deepEqual(workerState({ ...worker, shutdown_at: 990 }, 1000), ["Idle", "Shutting down…"]);
});
test("analysis texts say what is waiting and what happens next", () => {
    assert.match(waitingText(3, true, false), /3 songs are waiting\. They are analyzed automatically/);
    assert.match(waitingText(3, false, false), /waiting until you start/);
    assert.match(waitingText(0, true, false), /All songs are analyzed\. New uploads are analyzed automatically/);
    assert.equal(waitingText(3, true, true), "The analysis starts within a minute or two.");
    assert.equal(waitingText(18, true, false, { own: true, station_songs: 18 }), "18 songs are being analyzed now.");
    assert.equal(waitingText(20, true, false, { own: true, station_songs: 18 }), "18 songs are being analyzed now. 2 more follow in the next run.");
    assert.match(waitingText(4, true, false, { own: false }), /until the current analysis of another station has finished/);
    assert.match(libraryText(120), /only analyzed if you start it/);
    assert.match(libraryText(0), /Every song/);
    assert.equal(jobText("republish"), "Writing intros to AzuraCast…");
    assert.equal(jobText("publish"), "Writing intros to AzuraCast…");
    assert.equal(runResult("partial"), "Partly done");
});

test("batch progress names the step, its share done and the time left", () => {
    const analyze = { step: "analyze", done: 2, total: 8, started_at: 1000, step_started_at: 1300 };
    assert.equal(stepText({ ...analyze, songs: 40 }), "Analyzing 40 songs: 25% done");
    assert.equal(stepText({ ...analyze, songs: 4, done: 0, total: 2 }), "Analyzing 4 songs: 0% done");
    assert.equal(stepText(analyze), "Analyzing songs: 25% done");
    assert.equal(progressPercent({ ...analyze, done: 2.6 }), 33, "Stages within a part move the bar");
    assert.equal(stepText({ step: "worker", done: 0, total: 0 }), "Starting a GPU worker (usually about 5 minutes)");
    assert.equal(stepText({ step: "copy", done: 0, total: 0 }), "Copying results back");
    assert.equal(progressPercent(analyze), 25);
    assert.equal(progressPercent({ step: "upload", done: 0, total: 0 }), null);
    assert.equal(stepRemaining(analyze, 1500), 600, "Two parts took 200 s, six remain");
    assert.equal(stepRemaining({ step: "worker", step_started_at: 1000 }, 1100), 200);
    assert.equal(stepRemaining({ step: "analyze", done: 0, total: 8, step_started_at: 1000 }, 1100), null);
    assert.equal(activityLine(analyze, 1500), "Running for 8 min · about 10 min left in this step.");
    assert.equal(duration(30), "less than a minute");
    assert.equal(duration(3900), "1 h 5 min");
    assert.equal(runResult("failed", "worker"), "Failed while starting a GPU worker");
    assert.equal(runResult("interrupted"), "Interrupted");
});

test("progress details name the GPU, its location and the pipeline stage", () => {
    const gpu = { gpu: "NVIDIA GeForce RTX 4090", location: "EU-RO-1", price: 0.89 };
    assert.equal(place("EU-SE-1"), "EU-SE-1 (Sweden)");
    assert.equal(place("EUR-IS-1"), "EUR-IS-1 (Iceland)");
    assert.equal(place("US-TX-3"), "US-TX-3 (USA)");
    assert.equal(place("XX-1"), "XX-1");
    assert.equal(detailText({ step: "worker", detail: { phase: "search", ...gpu } }), "Renting RTX 4090 in EU-RO-1 (Romania) at USD 0.89/hour…");
    assert.equal(detailText({ step: "worker", detail: { phase: "boot", ...gpu } }),
        "RTX 4090 in EU-RO-1 (Romania) at USD 0.89/hour is starting and loading the analysis software.");
    assert.match(detailText({ step: "worker", detail: { phase: "no_stock", attempt: 3, max_price: 0.9 } }), /up to USD 0\.90\/hour.*attempt 3/);
    assert.equal(detailText({ step: "worker", detail: { phase: "reuse" } }), "Using the GPU worker that is already running.");
    assert.equal(detailText({ step: "upload", detail: { songs: 18, megabytes: 160 } }), "18 songs, 160 MB.");
    assert.equal(detailText({ step: "analyze", detail: { phase: "prepare", lanes: 3 } }), "Checking the GPU and loading the models.");
    assert.equal(detailText({ step: "analyze", detail: { phase: "run", stages: ["matrix", "qwen"], failed: 0 } }),
        "Comparing vocal detectors, transcribing lyrics.");
    assert.equal(detailText({ step: "analyze", detail: { phase: "run", stages: [], failed: 1 } }), "Some songs failed and are retried later.");
    assert.equal(detailText({ step: "copy", detail: null }), "");
});
import assert from "node:assert/strict";
import test from "node:test";
import {
    activityLine, blockedText, changedFields, duration, estimateText, jobText, libraryText, money, progressPercent, queueCounts, runResult,
    spendNote, stationForm, stepRemaining, stepText, waitingText,
} from "../frontend/detector.js";

test("detector form sends only changed station fields", () => {
    const saved = { enabled: false, mode: "shadow", auto_process: true, min_song_seconds: 60, exclude_playlists: [3],
        billing: "own", budget_usd: 10, shared_allowed: true, account: "station-1" };
    const form = stationForm(saved);
    assert.equal(form.account, undefined, "Only station fields are editable");
    form.exclude_playlists.push(4);
    assert.deepEqual(saved.exclude_playlists, [3], "Editing the form leaves the saved settings alone");
    form.enabled = true;
    assert.deepEqual(changedFields(form, saved), { enabled: true, exclude_playlists: [3, 4] });
    assert.deepEqual(changedFields(stationForm(saved), saved), {});
});

test("detector texts explain money, blocks and backfill estimates", () => {
    assert.equal(money(null), "–");
    assert.equal(money(3.456), "US$3.46");
    assert.match(blockedText("no Runpod key"), /Add a Runpod API key/);
    assert.equal(blockedText("something new"), "something new");
    assert.equal(blockedText(null), "");
    assert.deepEqual(queueCounts({ done: 2, pending: 1, known: 7 }), { done: 2, waiting: 1, failed: 0, library: 7, skipped: 0 });
    assert.match(estimateText(1000, 4), /Analyzing 1000 older songs takes a while and costs about US\$4\.00/);
    assert.match(estimateText(0, null), /already has an intro/);
});

test("spending notes name the budget that applies", () => {
    const status = (account, limit) => ({ settings: { account }, spend_month_usd: 1, limit_usd: limit });
    assert.equal(spendNote(status("station-1", 10)), "");
    assert.match(spendNote(status("station-1", 0)), /No monthly budget/);
    assert.match(spendNote(status("shared", 0)), /server administrator's account within its monthly budget/);
    assert.match(spendNote(status("shared", 5)), /Monthly limit for this station/);
});
test("analysis texts say what is waiting and what happens next", () => {
    assert.match(waitingText(3, true, false), /3 songs are waiting\. They are analyzed automatically/);
    assert.match(waitingText(3, false, false), /waiting until you start/);
    assert.match(waitingText(0, true, false), /No songs are waiting\. New uploads are analyzed automatically/);
    assert.equal(waitingText(3, true, true), "The analysis starts shortly.");
    assert.match(libraryText(120), /120 songs that were already in the library have no intro yet/);
    assert.match(libraryText(0), /Every song/);
    assert.equal(jobText("republish"), "Writing intros to AzuraCast…");
    assert.equal(runResult("partial"), "Partly done");
});

test("batch progress names the step, its share done and the time left", () => {
    const analyze = { step: "analyze", done: 2, total: 8, started_at: 1000, step_started_at: 1300 };
    assert.equal(stepText(analyze), "Analyzing songs: 2 of 8 parts");
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
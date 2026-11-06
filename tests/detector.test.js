import assert from "node:assert/strict";
import test from "node:test";
import {
    activityLine, balanceText, blockedText, changedFields, duration, estimateFigures, jobText, keyStationsText, libraryText, money,
    overBudget, progressPercent, queueCounts, runResult, spendNote, stationForm, stepRemaining, stepText, waitingText,
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
    assert.equal(money(3.456), "US$3.46");
    assert.match(blockedText("no Runpod key"), /Add a Runpod API key/);
    assert.equal(blockedText("something new"), "something new");
    assert.equal(blockedText(null), "");
    assert.deepEqual(queueCounts({ done: 2, pending: 1, known: 7 }), { done: 2, waiting: 1, failed: 0, library: 7, skipped: 0 });
    assert.deepEqual(estimateFigures({ songs: 1000, estimated_usd: 4 }, 9.5),
        [["Songs", "1000"], ["Estimated cost", "US$4.00"], ["Budget left this month", "US$9.50"]]);
    assert.deepEqual(estimateFigures({ songs: 3, estimated_usd: null }, null), [["Songs", "3"], ["Estimated cost", "Known after the first run"]]);
    assert.equal(overBudget({ estimated_usd: 4 }, 9.5), false);
    assert.equal(overBudget({ estimated_usd: 12 }, 9.5), true);
    assert.equal(overBudget({ estimated_usd: 12 }, null), false, "No budget, no limit");
});

test("spending notes describe the station budget, the key's stations and the Runpod balance", () => {
    assert.equal(spendNote({ limit_usd: 10 }), "");
    assert.match(spendNote({ limit_usd: 0 }), /No monthly budget/);
    assert.equal(keyStationsText(1), "This key is used by this station only.");
    assert.equal(keyStationsText(4), "This key is used by 4 stations.");
    assert.equal(balanceText(12.345), "Runpod balance: US$12.35.");
    assert.match(balanceText(4.3), /US\$4\.30\. Add funds soon/);
    assert.match(balanceText(null), /not checked yet/);
});
test("analysis texts say what is waiting and what happens next", () => {
    assert.match(waitingText(3, true, false), /3 songs are waiting\. They are analyzed automatically/);
    assert.match(waitingText(3, false, false), /waiting until you start/);
    assert.match(waitingText(0, true, false), /No songs are waiting\. New uploads are analyzed automatically/);
    assert.equal(waitingText(3, true, true), "The analysis starts shortly.");
    assert.match(libraryText(120), /only analyzed if you start it/);
    assert.match(libraryText(0), /Every song/);
    assert.equal(jobText("republish"), "Writing intros to AzuraCast…");
    assert.equal(jobText("publish"), "Writing intros to AzuraCast…");
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
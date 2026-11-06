import assert from "node:assert/strict";
import test from "node:test";
import { blockedText, changedFields, estimateText, money, queueCounts, spendLine, stationForm, workerText } from "../frontend/detector.js";

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

test("detector texts explain money, blocks, workers and backfill estimates", () => {
    assert.equal(money(null), "–");
    assert.equal(money(3.456), "US$3.46");
    assert.equal(spendLine(1.2, 0), "US$1.20 of no limit this month");
    assert.match(blockedText("no Runpod key"), /Add a Runpod API key/);
    assert.equal(blockedText("something new"), "something new");
    assert.equal(blockedText(null), "");
    assert.match(workerText(null), /No GPU worker/);
    assert.match(workerText({ pod_id: "abc", gpu_id: "RTX 4090", price: .89, idle_since: 1 }), /abc.*US\$0\.89\/h.*idle/);
    assert.deepEqual(queueCounts({ done: 2, pending: 1, known: 7 }), { done: 2, waiting: 1, failed: 0, library: 7, skipped: 0 });
    assert.match(estimateText(1000, 4), /1000 songs without an intro, about US\$4\.00/);
    assert.match(estimateText(0, null), /already has an intro/);
});
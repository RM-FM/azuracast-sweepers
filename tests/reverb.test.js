import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { createReverbPreview, REVERB_DECAYS, REVERB_DELAYS_MS, reverbLevel } from "../frontend/reverb.js";
import { FakeAudioContext } from "./fake-audio.js";

test("browser preview uses exactly the reflections of the Liquidsoap module", async () => {
    const module = await readFile(new URL("../liquidsoap/sweepers.liq", import.meta.url), "utf8");
    const reverb = module.slice(module.indexOf("def sweepers.reverb"));
    const delays = reverb.match(/delays="([\d|]+)"/)[1].split("|").map(Number);
    const decays = reverb.match(/\[(0\.\d+(?:, 0\.\d+)+)\]/)[1].split(", ").map(Number);
    assert.deepEqual(delays, REVERB_DELAYS_MS);
    assert.deepEqual(decays, REVERB_DECAYS);
});

test("preview level follows the switch and the intensity like the module", () => {
    assert.equal(reverbLevel(false, 80), 0);
    assert.equal(reverbLevel(true, 50), 0.5);
    assert.equal(reverbLevel(true, 140), 1);
    assert.equal(reverbLevel(true, ""), 0);
});

test("preview plays the cue range, changes the reverb live and stops", async () => {
    const fetched = [];
    const preview = createReverbPreview(FakeAudioContext, async (url) => { fetched.push(url); return new ArrayBuffer(8); });
    let ended = false;
    await preview.play({ audio_url: "/play/9", cue_in: 0.2, cue_out: 2.3 }, 0.5, () => { ended = true; });
    const context = FakeAudioContext.last;
    const source = context.sources[0];
    assert.deepEqual(source.started, [0, 0.2, 2.0999999999999996]);
    assert.ok(source.connections.includes(context.destination), "The dry voice is unchanged");
    assert.deepEqual(context.delays.map((delay) => Math.round(delay.delayTime.value * 1000)), REVERB_DELAYS_MS);
    assert.deepEqual(context.gains.map((gain) => gain.gain.value), REVERB_DECAYS.map((decay) => decay * 0.5));
    preview.setLevel(1);
    assert.deepEqual(context.gains.map((gain) => gain.gain.target), REVERB_DECAYS);
    assert.ok(preview.playing);
    source.onended();
    assert.ok(ended && !preview.playing, "Natural end is reported");
    await preview.play({ audio_url: "/play/9", cue_in: 0, cue_out: 9 }, 0, () => {});
    assert.deepEqual(fetched, ["/play/9"], "Decoded audio is reused");
    assert.deepEqual(context.sources[1].started, [0, 0, 3], "Cue-out never exceeds the file");
    preview.stop();
    assert.ok(context.sources[1].stopped && !preview.playing);
    preview.close();
    assert.ok(context.closed);
});

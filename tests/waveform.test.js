import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { compileScript, parse } from "@vue/compiler-sfc";
import { parseHTML } from "linkedom";

const { window } = parseHTML('<html><body><div id="app"></div></body></html>');
for (const key of ["window", "document", "Element", "HTMLElement", "SVGElement", "Node", "Document", "Event"]) {
    globalThis[key] = key === "window" ? window : window[key];
}
globalThis.getComputedStyle = () => ({ getPropertyValue: () => "" });
globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: [[0, 0]] }) });
const { createApp, h, nextTick, ref } = await import("vue");

class FakeWaveSurfer {
    static create(options) {
        FakeWaveSurfer.last = new FakeWaveSurfer();
        FakeWaveSurfer.last.options = options;
        return FakeWaveSurfer.last;
    }
    listeners = new Map();
    time = 0;
    on(name, callback) { this.listeners.set(name, callback); }
    emit(name, value) { this.listeners.get(name)?.(value); }
    async load() { this.emit("ready"); }
    async play() {
        if (this.reject) throw new Error("Playback blocked");
        this.emit("play");
    }
    pause() { this.emit("pause"); }
    setTime(time) { this.time = time; }
    getDuration() { return 180; }
    zoom(value) { this.zoomValue = value; }
    setVolume(value) { this.volume = value; }
    setMuted(value) { this.muted = value; }
    destroy() { this.destroyed = true; }
}
globalThis.__introTestWaveSurfer = FakeWaveSurfer;
class FakeRegions {
    static create() {
        FakeRegions.last = new FakeRegions();
        return FakeRegions.last;
    }
    added = 0;
    removed = 0;
    on(name, callback) { this.updated = callback; }
    addRegion(options) {
        this.added++;
        const region = { ...options,
            setOptions: (next) => Object.assign(region, next),
            remove: () => { this.removed++; },
        };
        this.marker = region;
        return region;
    }
}
globalThis.__introTestRegions = FakeRegions;
const moduleUrl = (source) => `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`;
const source = await readFile(new URL("../frontend/Waveform.vue", import.meta.url), "utf8");
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: "waveform-test", inlineTemplate: true }).content
    .replaceAll('from "vue"', `from ${JSON.stringify(import.meta.resolve("vue"))}`)
    .replaceAll('from "wavesurfer.js"', `from ${JSON.stringify(moduleUrl("export default globalThis.__introTestWaveSurfer;"))}`)
    .replaceAll('from "wavesurfer.js/dist/plugins/regions.js"', `from ${JSON.stringify(moduleUrl(
        "export default globalThis.__introTestRegions;",
    ))}`)
    .replaceAll('from "wavesurfer.js/dist/plugins/timeline.js"', `from ${JSON.stringify(moduleUrl(
        "export default { create: () => ({ timeline: true }) };",
    ))}`)
    .replaceAll('from "./Icon.vue"', `from ${JSON.stringify(moduleUrl(
        `import { h } from ${JSON.stringify(import.meta.resolve("vue"))}; export default { props: ['name'], setup: (p) => () => h('svg', {'data-icon': p.name}) };`,
    ))}`);
const { default: Waveform } = await import(moduleUrl(compiled));
const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const click = async (label) => {
    const button = [...document.querySelectorAll("button")].find((node) => node.textContent === label || node.getAttribute("aria-label") === label);
    assert.ok(button, `Button missing: ${label}`);
    assert.ok(!button.disabled);
    button.click();
    await settle();
};

test("native-style waveform has timeline, independent Play/Stop, zoom, volume and mute", async () => {
    let waveform;
    const app = createApp({
        render: () => h(Waveform, {
            ref: (value) => { waveform = value; }, audioUrl: "/audio", waveformUrl: "/wave", intro: 10.92,
        }, {
            controls: ({ ready }) => [
                h("button", { disabled: !ready }, "Set Intro"),
                h("button", { disabled: !ready }, "No Intro"),
            ],
        }),
    });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        const player = FakeWaveSurfer.last;
        const play = document.querySelector('button[aria-label="Play"]');
        assert.ok(document.querySelector('button[aria-label="Stop"].btn-dark'));
        assert.ok(document.querySelector('input[aria-label="Waveform Zoom"][max="256"]'));
        assert.ok(player.options.plugins[1].timeline);
        assert.equal(player.volume, Math.expm1(.55) / Math.expm1(1));
        const volume = document.querySelector('input[aria-label="Volume"]');
        volume.value = "50";
        volume.dispatchEvent(new Event("input", { bubbles: true }));
        await settle();
        assert.equal(player.volume, Math.expm1(.5) / Math.expm1(1));
        await click("Mute");
        assert.equal(player.muted, true);
        await click("Unmute");
        assert.equal(player.muted, false);
        await click("Play");
        assert.equal(play.getAttribute("aria-pressed"), "true");
        await click("Stop");
        assert.equal(play.getAttribute("aria-pressed"), "false");
        await waveform.playAt(10.92);
        await settle();
        assert.equal(player.time, 10.92);
        assert.equal(play.getAttribute("aria-pressed"), "true");
        player.emit("finish");
        await settle();
        assert.equal(play.getAttribute("aria-pressed"), "false", "Natural EOF resets playback state");
        await click("Play");
        waveform.pause();
        await settle();
        assert.equal(play.getAttribute("aria-pressed"), "false", "External pause resets playback state");
        player.reject = true;
        await click("Play");
        assert.equal(play.getAttribute("aria-pressed"), "false");
        assert.equal(document.querySelector('[role="alert"]').textContent, "Playback blocked");
        await waveform.playAt(10.92);
        await settle();
        assert.equal(document.querySelector('[role="alert"]').textContent, "Playback blocked");
        player.reject = false;
        await click("Play");
        assert.equal(document.querySelector('[role="alert"]'), null);
    } finally {
        const player = FakeWaveSurfer.last;
        app.unmount();
        assert.equal(player.destroyed, true);
    }
});

test("zoom and marker edits retain one player and one marker without redraw feedback", async () => {
    const intro = ref(10.92);
    const app = createApp({
        render: () => h(Waveform, {
            audioUrl: "/audio", waveformUrl: "/wave", intro: intro.value,
            onIntro: (value) => { intro.value = value; },
        }),
    });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        const player = FakeWaveSurfer.last;
        const regions = FakeRegions.last;
        const marker = regions.marker;
        const zoom = document.querySelector('input[aria-label="Waveform Zoom"]');
        for (const value of [20, 100, 256, 0, 60]) {
            zoom.value = String(value);
            zoom.dispatchEvent(new Event("input", { bubbles: true }));
            await settle();
            assert.equal(player.zoomValue, value);
            assert.equal(FakeWaveSurfer.last, player);
            assert.equal(regions.marker, marker);
            assert.equal(regions.added, 1);
            assert.equal(regions.removed, 0);
        }
        marker.start = 18.361;
        regions.updated(marker);
        await settle();
        assert.equal(intro.value, 18.36);
        assert.equal(marker.start, 18.36);
        assert.equal(regions.added, 1);
        intro.value = 0;
        await settle();
        assert.equal(marker.start, 0);
        intro.value = null;
        await settle();
        assert.equal(regions.removed, 1);
        intro.value = 5;
        await settle();
        assert.equal(regions.added, 2);
    } finally {
        app.unmount();
    }
});

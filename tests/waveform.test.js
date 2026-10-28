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
const { createApp, h, nextTick } = await import("vue");

class FakeWaveSurfer {
    static create() {
        FakeWaveSurfer.last = new FakeWaveSurfer();
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
    zoom() {}
    destroy() { this.destroyed = true; }
}
globalThis.__introTestWaveSurfer = FakeWaveSurfer;
const moduleUrl = (source) => `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`;
const source = await readFile(new URL("../frontend/Waveform.vue", import.meta.url), "utf8");
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: "waveform-test", inlineTemplate: true }).content
    .replaceAll('from "vue"', `from ${JSON.stringify(import.meta.resolve("vue"))}`)
    .replaceAll('from "wavesurfer.js"', `from ${JSON.stringify(moduleUrl("export default globalThis.__introTestWaveSurfer;"))}`)
    .replaceAll('from "wavesurfer.js/dist/plugins/regions.js"', `from ${JSON.stringify(moduleUrl(
        "export default { create: () => ({ on() {}, clearRegions() {}, addRegion() {} }) };",
    ))}`);
const { default: Waveform } = await import(moduleUrl(compiled));
const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const click = async (label) => {
    const button = [...document.querySelectorAll("button")].find((node) => node.textContent === label);
    assert.ok(button, `Button missing: ${label}`);
    assert.ok(!button.disabled);
    button.click();
    await settle();
};

test("waveform keeps Play, Set and Listen Intro on one toolbar before Zoom and tracks playback state", async () => {
    let waveform;
    const app = createApp({
        render: () => h(Waveform, {
            ref: (value) => { waveform = value; }, audioUrl: "/audio", waveformUrl: "/wave", intro: 10.92,
        }, {
            controls: ({ ready }) => [
                h("button", { disabled: !ready }, "Set"),
                h("button", { disabled: !ready, onClick: () => waveform.playAt(10.92) }, "Listen Intro"),
            ],
        }),
    });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        const player = FakeWaveSurfer.last;
        const play = document.querySelector("button");
        assert.equal(play.parentElement.children[1].textContent, "Set");
        assert.equal(play.parentElement.children[2].textContent, "Listen Intro");
        assert.ok(play.parentElement.children[3].textContent.includes("Zoom"));
        assert.equal(document.querySelectorAll("button").length, 3, "No separate Stop button");
        await click("Play");
        assert.equal(play.textContent, "Stop");
        assert.equal(play.getAttribute("aria-pressed"), "true");
        await click("Stop");
        assert.equal(play.textContent, "Play");
        await click("Listen Intro");
        assert.equal(player.time, 10.92);
        assert.equal(play.textContent, "Stop");
        player.emit("finish");
        await settle();
        assert.equal(play.textContent, "Play", "Natural EOF resets the label");
        await click("Play");
        waveform.pause();
        await settle();
        assert.equal(play.textContent, "Play", "External pause resets the label");
        player.reject = true;
        await click("Play");
        assert.equal(play.textContent, "Play");
        assert.equal(document.querySelector('[role="alert"]').textContent, "Playback blocked");
        await click("Listen Intro");
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

import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { compileScript, parse } from "@vue/compiler-sfc";
import { parseHTML } from "linkedom";

const { window } = parseHTML('<html><body><div id="app"></div></body></html>');
for (const key of ["window", "document", "Element", "HTMLElement", "SVGElement", "Node", "Document", "Event"]) {
    globalThis[key] = key === "window" ? window : window[key];
}
const { createApp, nextTick } = await import("vue");
const vue = import.meta.resolve("vue");
const stub = `import { defineComponent, h, ref } from ${JSON.stringify(vue)};
export default defineComponent({
    props: ['intro'], emits: ['intro', 'position'],
    setup(props, { emit, expose }) {
        expose({ ready: ref(true), playAt() {} });
        return () => h('div', [
            h('span', { id: 'marker' }, props.intro === null ? 'blank' : String(props.intro)),
            h('button', { type: 'button', id: 'move-marker', onClick: () => emit('intro', 14.75) }, 'Move marker'),
            h('button', { type: 'button', id: 'seek', onClick: () => emit('position', 18.36) }, 'Seek')
        ]);
    }
});`;
const stubUrl = `data:text/javascript;base64,${Buffer.from(stub).toString("base64")}`;
const source = await readFile(new URL("../frontend/App.vue", import.meta.url), "utf8");
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: "editor-test", inlineTemplate: true }).content
    .replaceAll('from "vue"', `from ${JSON.stringify(vue)}`)
    .replaceAll('from "./Waveform.vue"', `from ${JSON.stringify(stubUrl)}`);
const { default: App } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`);
const writes = [];
let releaseSave;
globalThis.fetch = async (url, options) => {
    let data;
    if (url.endsWith("/state")) {
        data = { csrf: "session-token", station: { id: 1, name: "Test" }, settings: {
            enabled: false, playlist_id: null, max_wait: null, margin: .8, duck_db: -7, jingle_gain: 1,
            minimum_songs: 0, count_jingle_mode: true, jingle_playlist_ids: [], longest_fit: false, length_tolerance: 20,
        }, playlists: [{ id: 10, name: "Sweepers" }], jingle_playlists: [
            { id: 20, name: "Station IDs", is_jingle: true }, { id: 21, name: "Promos", is_jingle: false },
        ] };
    } else if (url.includes("/media?")) {
        data = { rows: [
            { id: 1, path: "First song", duration: 180, intro_end: 10, audio_url: "/first", waveform_url: "/wave/first" },
            { id: 2, path: "Second song", duration: 180, intro_end: 6, audio_url: "/second", waveform_url: "/wave/second" },
        ] };
    } else {
        writes.push({ url, options, body: JSON.parse(options.body) });
        if (releaseSave) await new Promise((resolve) => { releaseSave = resolve; });
        data = { success: true, intro_end: JSON.parse(options.body).intro_end };
    }
    return { ok: true, json: async () => data };
};
const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const click = async (label) => {
    const button = [...document.querySelectorAll("button")].find((node) => node.textContent.trim() === label);
    assert.ok(button, `Button missing: ${label}`);
    assert.ok(!button.disabled, `Button disabled: ${label}`);
    button.dispatchEvent(new Event("click", { bubbles: true }));
    await settle();
};
const input = () => document.querySelector("article input[type=number]");
const setInput = async (value) => {
    input().value = value;
    input().dispatchEvent(new Event("input", { bubbles: true }));
    await settle();
};
const save = async () => {
    document.querySelector("article form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
    await settle();
};

test("intro editor saves one field from numeric, marker and playhead input", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        document.querySelector(".media-list button").click();
        await settle();
        await setInput("12.5");
        assert.equal(document.querySelector("#marker").textContent, "12.5");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: 12.5 });
        assert.equal(writes.at(-1).url, "/sweepers/1/intro/1");
        assert.equal(writes.at(-1).options.headers["X-CSRF-Token"], "session-token");
        assert.equal(writes.at(-1).options.credentials, "same-origin");
        await click("Move marker");
        assert.equal(input().value, "14.75");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: 14.75 });
        await click("Seek");
        await click("Set at playhead");
        assert.equal(input().value, "18.36");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: 18.36 });
        await click("No intro (0 s)");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: 0 });
        await click("Clear cue");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: null });
        await setInput("181");
        const count = writes.length;
        await save();
        assert.equal(writes.length, count, "Out-of-file cue was submitted");
        await setInput("14");
        releaseSave = true;
        await save();
        document.querySelectorAll(".media-list button")[1].click();
        await settle();
        releaseSave();
        releaseSave = null;
        await settle();
        assert.equal(input().value, "6", "Saving the first song changed the second song's draft");
    } finally {
        app.unmount();
    }
});

test("settings save spacing, playlist selection, longest fit and nullable delay", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
    app.mount(document.querySelector("#app"));
    const field = (label) => [...document.querySelectorAll("section.card label")]
        .find((node) => node.textContent.includes(label)).querySelector("input,select");
    const change = async (label, value, checkbox = false) => {
        const node = field(label);
        if (checkbox) node.checked = value;
        else node.value = value;
        node.dispatchEvent(new Event(checkbox ? "change" : "input", { bubbles: true }));
        await settle();
    };
    const submit = async () => {
        document.querySelector("section.card form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await settle();
        return writes.at(-1).body;
    };
    try {
        await settle();
        assert.equal(field("Limit start delay").checked, false);
        assert.equal((await submit()).max_wait, null);
        await change("Minimum songs", "2");
        await change("Count Jingle Mode", false, true);
        await change("Prefer the longest", true, true);
        await change("Intro length tolerance", "20");
        const select = field("Additional jingle playlists");
        assert.ok(select.hasAttribute("multiple"));
        Object.defineProperty(select, "multiple", { value: true });
        for (const option of select.options) Object.defineProperty(option, "selected", { configurable: true, writable: true, value: true });
        select.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
        await change("Limit start delay", true, true);
        const settings = await submit();
        assert.equal(settings.max_wait, 0);
        assert.equal(settings.minimum_songs, 2);
        assert.equal(settings.count_jingle_mode, false);
        assert.equal(settings.longest_fit, true);
        assert.equal(settings.length_tolerance, 20);
        assert.deepEqual(settings.jingle_playlist_ids, [20, 21]);
        await change("Maximum start delay", "5");
        assert.equal((await submit()).max_wait, 5);
        await change("Limit start delay", false, true);
        assert.equal((await submit()).max_wait, null);
        assert.equal(writes.at(-1).url, "/sweepers/1/settings");
    } finally {
        app.unmount();
    }
});

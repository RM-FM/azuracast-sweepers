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
    .replaceAll('from "./Waveform.vue"', `from ${JSON.stringify(stubUrl)}`)
    .replaceAll('from "./reverb.js"', `from ${JSON.stringify(new URL("../frontend/reverb.js", import.meta.url).href)}`);
const { default: App } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`);
const writes = [];
let releaseSave;
let needsRestart = false;
let moduleState = { state: "active", message: "The sweeper module is running on this station." };
globalThis.fetch = async (url, options) => {
    let data;
    if (url.endsWith("/state")) {
        data = { csrf: "session-token", station: { id: 1, name: "Test" }, queue_length: 3, module: moduleState, settings: {
            enabled: true, playlist_id: null, limit_delay: false, max_wait: 10, margin: .5, duck_db: -7, jingle_gain: 1,
            songs_after_jingle: 1, songs_after_sweeper: 1, songs_before_jingle: 0, early_start: 2,
            count_jingle_mode: true, jingle_playlist_ids: [], longest_fit: false, length_tolerance: 20, swap_for_intro: false,
            reverb: false, reverb_intensity: 50, swap_sequential: false, excluded_playlist_ids: [],
        }, playlists: [{ id: 10, name: "Sweepers" }], jingle_playlists: [
            { id: 20, name: "Station IDs", is_jingle: true }, { id: 21, name: "Promos", is_jingle: false },
        ] };
    } else if (url.startsWith("/play/")) {
        data = {};
    } else if (url.includes("/media?playlist=")) {
        previewRequests.push(url);
        data = { rows: [
            { id: 31, title: "male", artist: "radiomonster.fm", path: "male.mp3", cue_in: 0.2, cue_out: 2.3, audio_url: "/play/31" },
            { id: 32, title: null, artist: null, path: "female.mp3", cue_in: 0, cue_out: 3, audio_url: "/play/32" },
        ] };
    } else if (url.includes("/media?")) {
        data = { rows: [
            { id: 1, path: "First song", duration: 180, intro_end: 10, audio_url: "/first", waveform_url: "/wave/first" },
            { id: 2, path: "Second song", duration: 180, intro_end: 6, audio_url: "/second", waveform_url: "/wave/second" },
        ] };
    } else if (url.endsWith("/history")) {
        data = { limit: 200, rows: [
            { at: 1792948000, offset: 9.66, song: { artist: "ABBA", title: "Voulez-Vous", path: "abba.mp3" },
                sweeper: { artist: "RadioMonster.FM", title: "Dropin 03 (no ads)", path: "dropin-03.mp3" },
                moved: true, moved_before: { artist: "Queen", title: "Bohemian Rhapsody", path: "queen.mp3" } },
            { at: 1792947000, offset: 2, song: { artist: null, title: null, path: "untagged.mp3" }, sweeper: null,
                moved: false, moved_before: null },
        ] };
    } else {
        writes.push({ url, options, body: JSON.parse(options.body) });
        if (releaseSave) await new Promise((resolve) => { releaseSave = resolve; });
        data = { success: true, needs_restart: needsRestart, intro_end: JSON.parse(options.body).intro_end };
    }
    return { ok: true, json: async () => data, arrayBuffer: async () => new ArrayBuffer(8) };
};
const previewRequests = [];
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
    const app = createApp(App, { base: "/sweepers/1", csrf: "", view: "intros" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        assert.equal(document.querySelector("form.sweepers-settings"), null, "The Intros page shows no settings");
        assert.ok(document.querySelector("aside.card .card-header.text-bg-primary"));
        assert.ok(document.querySelector(".media-list table.table-striped.table-hover"));
        assert.ok(document.querySelector(".media-track button.btn-primary"));
        document.querySelector(".media-track button").click();
        await settle();
        assert.equal(document.querySelector("article").closest("tr").previousElementSibling, document.querySelector(".media-track"));
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
        document.querySelectorAll(".media-track button")[1].click();
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
    const field = (label) => {
        const node = [...document.querySelectorAll("section.card label")]
            .find((node) => node.textContent.includes(label));
        return node.querySelector("input,select") ?? document.getElementById(node.getAttribute("for"));
    };
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
        assert.equal(field("Enable sweepers").checked, true);
        assert.equal(field("Limit start delay").checked, false);
        assert.equal(field("Jingle gain").value, "100", "Jingle gain is shown in percent");
        const first = await submit();
        assert.equal(first.limit_delay, false);
        assert.equal(first.max_wait, 10, "The maximum start delay keeps its value while the limit is off");
        assert.equal(first.jingle_gain, 1);
        assert.equal(field("Move a song with an intro forward").checked, false, "Moving songs forward is off by default");
        assert.equal(first.swap_for_intro, false);
        assert.equal(first.swap_sequential, false);
        assert.deepEqual(first.excluded_playlist_ids, []);
        assert.equal(first.reverb, false, "Reverb is off by default");
        assert.ok(![...document.querySelectorAll("section.card label")].some((node) => node.textContent.includes("Reverb intensity")),
            "Intensity appears only when reverb is on");
        assert.equal(document.querySelector('[role="status"]').textContent, "Settings saved.");
        assert.doesNotMatch(document.querySelector('[role="status"]').textContent, /restart/i);
        needsRestart = true;
        await submit();
        assert.match(document.querySelector('[role="status"]').textContent, /other changes that need a broadcasting restart/);
        needsRestart = false;
        await change("after a jingle", "3");
        await change("after a sweeper", "2");
        await change("before a jingle", "1");
        assert.equal(field("before a jingle").getAttribute("max"), "3", "Before-jingle limit follows the AutoDJ queue");
        assert.match(document.querySelector("section.card").textContent, /AutoDJ queue length \(3\)/);
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
        assert.equal(field("Maximum start delay").value, "10", "Maximum start delay shows its 10-second default");
        assert.equal(field("Early start").value, "2", "Early start shows its 2-second default");
        await change("Early start", "3.5");
        await change("Jingle gain", "70");
        await change("Move a song with an intro forward", true, true);
        await change("Also move songs from sequential playlists", true, true);
        const excluded = field("Exclude these playlists");
        assert.ok(excluded.hasAttribute("multiple"));
        Object.defineProperty(excluded, "multiple", { value: true });
        for (const option of excluded.options) {
            Object.defineProperty(option, "selected", { configurable: true, writable: true, value: option.textContent.trim() === "Promos" });
        }
        excluded.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
        await change("Add reverb to the sweeper voice", true, true);
        assert.equal(field("Reverb intensity").value, "50");
        await change("Reverb intensity", "40");
        const settings = await submit();
        assert.equal(settings.reverb, true);
        assert.equal(settings.reverb_intensity, 40);
        assert.equal(settings.swap_for_intro, true);
        assert.equal(settings.swap_sequential, true);
        assert.deepEqual(settings.excluded_playlist_ids, [21], "Playlists without sweepers are saved");
        assert.equal(settings.limit_delay, true);
        assert.equal(settings.max_wait, 10);
        assert.equal(settings.jingle_gain, 0.7, "70% is stored as the factor 0.7");
        assert.equal(settings.early_start, 3.5);
        assert.equal(settings.songs_after_jingle, 3);
        assert.equal(settings.songs_after_sweeper, 2);
        assert.equal(settings.songs_before_jingle, 1);
        assert.equal("minimum_songs" in settings, false);
        assert.equal(settings.count_jingle_mode, false);
        assert.equal(settings.longest_fit, true);
        assert.equal(settings.length_tolerance, 20);
        assert.deepEqual(settings.jingle_playlist_ids, [20, 21]);
        await change("Maximum start delay", "5");
        assert.equal((await submit()).max_wait, 5);
        await change("Limit start delay", false, true);
        const off = await submit();
        assert.equal(off.limit_delay, false);
        assert.equal(off.max_wait, 5, "Switching the limit off keeps the entered maximum");
        assert.equal(writes.at(-1).url, "/sweepers/1/settings");
    } finally {
        app.unmount();
    }
});

test("module status tells when a one-time restart is still required", async () => {
    moduleState = { state: "restart", message: "The sweeper module is not running. Restart broadcasting once after installing or updating the plugin." };
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        const status = document.querySelector('[data-module-status="restart"]');
        assert.ok(status, "Restart requirement is not shown");
        assert.ok(status.classList.contains("alert-warning"));
        assert.match(status.textContent, /Restart broadcasting once/);
        document.querySelector("section.card form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await settle();
        assert.match(document.querySelector('p.alert-info[role="status"]').textContent, /apply once the sweeper module runs/);
    } finally {
        app.unmount();
        moduleState = { state: "active", message: "The sweeper module is running on this station." };
    }
});

test("playback timeline lists played sweepers with song, sweeper, start time and moved songs", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token", view: "history" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        await settle();
        assert.equal(document.querySelector("form.sweepers-settings"), null);
        assert.equal(document.querySelector("#sweepers-history-heading").textContent, "Sweeper Playback Timeline");
        const rows = [...document.querySelectorAll("#app tbody tr")];
        const parts = (row, column) => [...row.querySelectorAll("td")[column].querySelectorAll(".timeline-title, .timeline-artist, .badge, .timeline-moved small")]
            .map((node) => node.textContent.trim());
        assert.equal(rows.length, 2);
        assert.deepEqual(parts(rows[0], 1), ["Voulez-Vous", "ABBA", "Moved forward", "ahead of Queen – Bohemian Rhapsody"]);
        assert.deepEqual(parts(rows[0], 2), ["Dropin 03 (no ads)", "RadioMonster.FM"]);
        assert.equal(rows[0].querySelectorAll("td")[3].textContent.trim(), "9.7 s");
        assert.deepEqual(parts(rows[1], 1), ["untagged.mp3"]);
        assert.deepEqual(parts(rows[1], 2), ["Deleted file"]);
        assert.equal(rows[1].querySelectorAll("td")[3].textContent.trim(), "2.0 s");
        assert.equal(rows[1].querySelector(".timeline-moved"), null, "Songs in their planned order are not marked");
        const text = document.querySelector("#app").textContent;
        assert.match(text, /Songs that played with a sweeper\./);
        assert.doesNotMatch(text, /newest first|last 200/);
    } finally {
        app.unmount();
    }
});

test("standalone page switches between Settings, Intros and Playback Timeline with tabs", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token", standalone: true });
    app.mount(document.querySelector("#app"));
    const tab = (label) => [...document.querySelectorAll(".nav-tabs button")].find((node) => node.textContent === label);
    try {
        await settle();
        assert.ok(document.querySelector("form.sweepers-settings"));
        assert.equal(document.querySelector("#sweepers-settings-heading").textContent, "Sweeper Settings");
        assert.equal(tab("Settings").getAttribute("aria-selected"), "true");
        tab("Playback Timeline").dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        await settle();
        assert.equal(document.querySelector("form.sweepers-settings"), null);
        assert.match(document.querySelector("#app").textContent, /Voulez-Vous/);
        tab("Intros").dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        await settle();
        assert.ok(document.querySelector(".media-track"));
    } finally {
        app.unmount();
    }
});

test("reverb preview plays the selected sweeper and follows the reverb settings live", async () => {
    const { FakeAudioContext } = await import("./fake-audio.js");
    window.AudioContext = FakeAudioContext;
    previewRequests.length = 0;
    const writesBefore = writes.filter((write) => write.url.endsWith("/settings")).length;
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token", view: "settings" });
    app.mount(document.querySelector("#app"));
    const label = (text) => [...document.querySelectorAll("section.card label")].find((node) => node.textContent.includes(text));
    const input = (text) => label(text).querySelector("input,select") ?? document.getElementById(label(text).getAttribute("for"));
    const button = () => document.querySelector(".sweepers-preview button");
    try {
        await settle();
        assert.match(document.querySelector(".sweepers-preview").textContent, /Choose a sweeper playlist above/);
        assert.ok(button().disabled, "Nothing to play without a sweeper playlist");
        const playlist = input("Sweeper playlist");
        for (const option of playlist.options) {
            Object.defineProperty(option, "selected", { configurable: true, writable: true, value: option.textContent.trim() === "Sweepers" });
        }
        playlist.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
        await settle();
        assert.deepEqual(previewRequests, ["/sweepers/1/media?playlist=10"], "Unsaved playlist choice loads its sweepers");
        assert.deepEqual([...input("Preview sweeper").options].map((option) => option.textContent.trim()),
            ["radiomonster.fm – male", "female.mp3"]);
        button().dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        await settle();
        const context = FakeAudioContext.last;
        assert.equal(button().textContent.trim(), "Stop");
        assert.deepEqual(context.sources[0].started, [0, 0.2, 2.0999999999999996], "Plays the cue range");
        assert.ok(context.gains.every((gain) => gain.gain.value === 0), "Reverb off: dry voice only");
        const reverb = input("Add reverb to the sweeper voice");
        reverb.checked = true;
        reverb.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
        assert.equal(context.gains[0].gain.target, 0.1015, "Switching the reverb on is heard right away (50%)");
        const intensity = input("Reverb intensity");
        intensity.value = "100";
        intensity.dispatchEvent(new Event("input", { bubbles: true }));
        await settle();
        assert.equal(context.gains[0].gain.target, 0.203, "Intensity changes are heard right away");
        button().dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        assert.ok(context.sources[0].stopped);
        assert.equal(button().textContent.trim(), "Play");
        assert.equal(writes.filter((write) => write.url.endsWith("/settings")).length, writesBefore,
            "Previewing never saves settings");
    } finally {
        app.unmount();
        delete window.AudioContext;
    }
});
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
    setup(props, { emit, expose, slots }) {
        expose({ ready: ref(true), playAt() {}, pause() {} });
        return () => h('div', [
            h('span', { id: 'marker' }, props.intro === null ? 'blank' : String(props.intro)),
            h('button', { type: 'button', id: 'move-marker', onClick: () => emit('intro', 14.75) }, 'Move marker'),
            h('button', { type: 'button', id: 'seek', onClick: () => emit('position', 18.36) }, 'Seek'),
            slots.controls?.({ ready: true })
        ]);
    }
});`;
const moduleUrl = (code) => `data:text/javascript;base64,${Buffer.from(code).toString("base64")}`;
const datePicker = `import { defineComponent, h } from ${JSON.stringify(vue)};
export const VueDatePicker = defineComponent({
    props: ['modelValue', 'presetDates', 'timezone', 'minDate'], emits: ['update:modelValue'],
    setup(props, { emit, slots }) {
        return () => h('div', { class: 'dp-stub' }, [
            slots['dp-input']?.({ value: props.modelValue.map((date) => date.toISOString()).join(' - ') }),
            ...(props.presetDates ?? []).map((preset) => h('button', {
                type: 'button', class: 'dp-preset', onClick: () => emit('update:modelValue', preset.value()),
            }, preset.label)),
        ]);
    }
});`;
const frontend = new URL("../frontend/", import.meta.url);
const modules = { "vue": vue, "./Waveform.vue": moduleUrl(stub), "@vuepic/vue-datepicker": moduleUrl(datePicker) };
const compileComponent = async (name) => {
    const { descriptor } = parse(await readFile(new URL(name, frontend), "utf8"));
    let code = compileScript(descriptor, { id: `editor-test-${name}`, inlineTemplate: true }).content;
    for (const spec of new Set([...code.matchAll(/from "([^"]+)"/g)].map((match) => match[1]))) {
        modules[spec] ??= spec.endsWith(".vue") ? await compileComponent(spec.slice(2)) : new URL(spec, frontend).href;
        code = code.replaceAll(`from "${spec}"`, `from ${JSON.stringify(modules[spec])}`);
    }
    return moduleUrl(code);
};
const { default: App } = await import(await compileComponent("App.vue"));
const writes = [];
let releaseSave;
let needsRestart = false;
let mediaResponse = null;
const mediaRequests = [];
let moduleState = { state: "active", message: "The sweeper module is loaded and active on this station." };
let restartInfo = null;
let releaseInfo = { installed: { version: "1.0.1", branch: "main", commit: "ecd7ff2" }, checked: true,
    repository_url: "https://github.com/RM-FM/azuracast-sweepers", latest: null, updates: [], restart: null };
const restarts = [];
globalThis.fetch = async (url, options) => {
    let data;
    if (url.endsWith("/release")) {
        data = releaseInfo;
    } else if (url === "/api/station/1/restart") {
        restarts.push(options);
        data = { success: true, message: "Station restarted." };
    } else if (url.endsWith("/state")) {
        data = { csrf: "session-token", station: { id: 1, name: "Test" }, restart: restartInfo, queue_length: 3, module: moduleState, settings: {
            enabled: true, playlist_ids: [], limit_delay: false, max_wait: 10, margin: .5, duck_db: -7, jingle_gain: 1,
            songs_after_jingle: 1, songs_after_sweeper: 1, songs_before_jingle: 0, early_start: 2,
            count_jingle_mode: true, jingle_playlist_ids: [], longest_fit: false, length_tolerance: 20, swap_for_intro: false,
            reverb: false, reverb_intensity: 25, swap_sequential: false, swap_from_playlist: false,
            excluded_playlist_ids: [], sweeper_gap: 1,
        }, playlists: [
            { id: 10, name: "Sweepers", enabled: true, scheduled: false, source: "songs" },
            { id: 11, name: "Christmas", enabled: true, scheduled: true, source: "songs" },
            { id: 12, name: "Retired", enabled: false, scheduled: false, source: "remote_url" },
        ], jingle_playlists: [
            { id: 20, name: "Station IDs", is_jingle: true, source: "songs" }, { id: 21, name: "Promos", is_jingle: false, source: "playlists" },
        ] };
    } else if (url.startsWith("/play/")) {
        data = {};
    } else if (url.includes("/media?playlist=")) {
        previewRequests.push(url);
        data = { rows: [
            { id: 31, title: "male", artist: "radiomonster.fm", path: "male.mp3", cue_in: 0.2, cue_out: 2.3, audio_url: "/play/31" },
            { id: 32, title: null, artist: null, path: "female.mp3", cue_in: 0, cue_out: 3, audio_url: "/play/32" },
        ] };
    } else if (url.includes("/media?id=")) {
        mediaLookups.push(url);
        data = { rows: [
            { id: 5, path: "queen.mp3", duration: 354, intro_end: null, audio_url: "/queen", waveform_url: "/wave/queen" },
        ] };
    } else if (url.includes("/media?")) {
        mediaRequests.push(url);
        if (mediaResponse) return mediaResponse(url);
        data = { total: 2, rows: [
            { id: 1, path: "First song", duration: 180, intro_end: 10, audio_url: "/first", waveform_url: "/wave/first" },
            { id: 2, path: "Second song", duration: 180, intro_end: 6, audio_url: "/second", waveform_url: "/wave/second" },
        ] };
    } else if (url.includes("/history?")) {
        historyRequests.push(url);
        const rows = [
            { at: 1792949000, offset: null, result: "scheduled", upcoming: true, song: { artist: "Toto", title: "Africa", path: "africa.mp3" },
                sweeper: null, moved: false, moved_before: null },
            { at: 1792948500, offset: null, result: "no_intro", unmoved: "move_none",
                song: { id: 5, artist: "Queen", title: "Bohemian Rhapsody", path: "queen.mp3", intro: null },
                sweeper: null, moved: false, moved_before: null },
            { at: 1792948200, offset: null, result: "jingle", song: { id: 42, artist: "RadioMonster.FM", title: "Dropin 01", path: "dropin-01.mp3" },
                sweeper: null, moved: false, moved_before: null },
            { at: 1792948000, offset: 9.66, result: "played", song: { id: 7, artist: "ABBA", title: "Voulez-Vous", path: "abba.mp3", intro: 12.25 },
                sweeper: { artist: "RadioMonster.FM", title: "Dropin 03 (no ads)", path: "dropin-03.mp3" },
                moved: true, moved_before: { artist: "Queen", title: "Bohemian Rhapsody", path: "queen.mp3" } },
            { at: 1792947500, offset: null, result: "scheduled", song: { artist: "Wham!", title: "I'm Your Man", path: "wham.mp3" },
                sweeper: null, moved: false, moved_before: null, replaced: true,
                replaced_before: { title: "No Intro", artist: "Original Artist", path: "original.mp3" } },
            { at: 1792947000, offset: 2, result: "played", song: { artist: null, title: null, path: "untagged.mp3" }, sweeper: null,
                moved: false, moved_before: null },
            { at: 1792946000, offset: null, result: "intro_before_cue", song: { id: 8, artist: "Sting", title: "Fragile", path: "sting.mp3", intro: 0 },
                sweeper: null, moved: false, moved_before: null },
        ].filter((row) => !url.includes("played=1") || row.result === "played");
        data = { rows, total: rows.length, page: 1, per_page: 25, retention_days: 7 };
    } else {
        writes.push({ url, options, body: JSON.parse(options.body) });
        if (releaseSave) await new Promise((resolve) => { releaseSave = resolve; });
        data = { success: true, needs_restart: needsRestart, intro_end: JSON.parse(options.body).intro_end };
    }
    return { ok: true, json: async () => data, arrayBuffer: async () => new ArrayBuffer(8) };
};
const previewRequests = [];
const mediaLookups = [];
const historyRequests = [];
const activePage = () => document.querySelector(".datatable-toolbar-top .page-item.active")?.textContent.trim();
const goToPage = async (number) => {
    const link = [...document.querySelectorAll(".datatable-toolbar-top .page-link")].find((node) => node.textContent.trim() === String(number));
    assert.ok(link, `Page link missing: ${number}`);
    link.dispatchEvent(new Event("click", { bubbles: true, cancelable: true }));
    await settle();
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
    const app = createApp(App, { base: "/sweepers/1", csrf: "", view: "intros" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        assert.equal(document.querySelector("form.sweepers-settings"), null, "The Intros page shows no settings");
        assert.ok(document.querySelector("section.card .card-header.text-bg-primary .sweepers-scan.btn-dark"));
        assert.ok(document.querySelector(".media-list table.table-striped.table-hover"));
        assert.ok(document.querySelector(".media-track button.btn-primary"));
        document.querySelector(".media-track button").click();
        await settle();
        const editorText = document.querySelector("article").textContent;
        assert.ok(!editorText.includes("Saved Intro End"));
        assert.ok(!editorText.includes("Original file position"));
        assert.ok(!editorText.includes("Move the marker"));
        assert.ok(!editorText.includes("Listen −3"));
        for (const label of ["Set", "Listen Intro", "No Intro", "Clear", "Save Intro"]) {
            assert.ok([...document.querySelectorAll("article button")].some((button) => button.textContent.trim() === label));
        }
        assert.equal(document.querySelector(".media-list tbody tr").querySelector("article h2").textContent, "First song",
            "The editor replaces the track row");
        assert.equal(document.querySelectorAll(".media-track").length, 1);
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
        await click("Set");
        assert.equal(input().value, "18.36");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: 18.36 });
        await click("No Intro");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: 0 });
        await click("Clear");
        await save();
        assert.deepEqual(writes.at(-1).body, { intro_end: null });
        await setInput("181");
        const count = writes.length;
        await save();
        assert.equal(writes.length, count, "Out-of-file cue was submitted");
        await setInput("14");
        releaseSave = true;
        await save();
        document.querySelector('.media-track button[aria-label="Edit intro: Second song"]').click();
        await settle();
        releaseSave();
        releaseSave = null;
        await settle();
        assert.equal(input().value, "6", "Saving the first song changed the second song's draft");
    } finally {
        app.unmount();
    }
});

class IntroAudio {
    static instances = [];
    static reject = false;
    constructor(url) {
        this.url = url;
        this.currentTime = 0;
        this.duration = 180;
        IntroAudio.instances.push(this);
    }
    play() {
        this.onloadedmetadata?.();
        return IntroAudio.reject ? Promise.reject(new Error("Playback blocked")) : Promise.resolve();
    }
    pause() { this.paused = true; }
    removeAttribute() {}
    load() {}
    tick(time) {
        this.currentTime = time;
        this.ontimeupdate?.();
    }
}
globalThis.Audio = IntroAudio;
const mediaResult = (rows, total = rows.length) => ({ ok: true, json: async () => ({ rows, total, page: 1, per_page: 25 }) });
const track = (id, intro = 10) => ({
    id, path: `Track ${id}`, duration: 180, intro_end: intro, audio_url: `/audio/${id}`, waveform_url: `/wave/${id}`,
});
const mountIntros = async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "", view: "intros", standalone: true });
    app.mount(document.querySelector("#app"));
    await settle();
    return app;
};
const lastAudio = () => IntroAudio.instances.at(-1);
const typeSearch = async (value) => {
    const node = document.querySelector("#search");
    node.value = value;
    node.dispatchEvent(new Event("input", { bubbles: true }));
    await settle();
};
const debounceSearch = async () => {
    await new Promise((resolve) => setTimeout(resolve, 330));
    await settle();
};

test("intro scan plays five seconds from saved original-file cues through every page beyond 100", async () => {
    mediaResponse = async (url) => {
        const page = Number(new URL(url, "http://localhost").searchParams.get("page"));
        return mediaResult(Array.from({ length: page === 5 ? 2 : 25 }, (_, i) => {
            const id = (page - 1) * 25 + i + 1;
            return track(id, id === 1 ? null : id === 2 ? 0 : 10);
        }), 102);
    };
    const app = await mountIntros();
    const start = IntroAudio.instances.length;
    try {
        assert.equal(activePage(), "1");
        assert.ok([...document.querySelectorAll(".datatable-toolbar-top .page-link")].some((node) => node.textContent.trim() === "5"),
            "Pagination covers all 102 matching files");
        assert.equal(document.querySelector("form.search button"), null);
        assert.ok(document.querySelector('.datatable-toolbar-top button.btn-secondary[title="Refresh rows"]'));
        const scan = document.querySelector(".sweepers-scan");
        assert.equal(scan.textContent.trim(), "Scan Intros");
        assert.ok(scan.classList.contains("btn-dark"));
        assert.ok(scan.querySelector('svg[aria-hidden="true"]'));
        await click("Scan Intros");
        assert.equal(scan.textContent.trim(), "Stop");
        assert.equal(scan.getAttribute("aria-pressed"), "true");
        for (let id = 2; id <= 102; id++) {
            const player = lastAudio();
            const cue = id === 2 ? 0 : 10;
            assert.equal(player.url, `/audio/${id}`);
            assert.equal(player.currentTime, cue, "Always starts at saved Intro End");
            player.tick(cue + 4.99);
            assert.equal(player.paused, undefined, "Does not advance before five seconds");
            player.tick(cue + 5);
            await settle();
            assert.equal(player.paused, true);
        }
        assert.equal(IntroAudio.instances.length - start, 101, "Blank skipped; zero is playable; all 102 results covered");
        assert.equal(activePage(), "5");
        assert.ok(document.body.textContent.includes("Finished listening"));
        assert.equal(scan.textContent.trim(), "Scan Intros");
        assert.equal(scan.getAttribute("aria-pressed"), "false");
        assert.equal(document.querySelector("article"), null, "Scanning does not open the editor");
    } finally {
        app.unmount();
        mediaResponse = null;
    }
});

test("single-track listening, early EOF, stop and editor opening release audio", async () => {
    const app = await mountIntros();
    try {
        await click("Intro");
        assert.equal(lastAudio().currentTime, 10);
        assert.equal(document.querySelector("article"), null);
        const single = lastAudio();
        single.onended();
        await settle();
        assert.equal(single.paused, true);
        await click("Scan Intros");
        lastAudio().onended();
        await settle();
        assert.equal(lastAudio().currentTime, 6);
        await click("Stop");
        const stopped = lastAudio();
        assert.equal(stopped.paused, true);
        assert.equal(stopped.onended, null);
        await click("Intro");
        await click("Edit intro");
        assert.equal(lastAudio().paused, true);
        assert.ok(document.querySelector("article"));
        await click("Intro");
        assert.equal(document.querySelector("article"), null, "Listening closes and pauses an open waveform");
    } finally {
        app.unmount();
        assert.equal(lastAudio().paused, true, "Unmounting releases audio");
    }
});

test("live search debounces, resets pagination, stops playback and ignores stale responses", async () => {
    let releaseOld;
    mediaResponse = async (url) => {
        const query = new URL(url, "http://localhost").searchParams;
        if (query.get("search") === "old") return new Promise((resolve) => { releaseOld = resolve; });
        return mediaResult([track(query.get("search") === "new" ? 9 : 1)], 101);
    };
    const app = await mountIntros();
    try {
        await goToPage(2);
        assert.equal(activePage(), "2");
        await click("Intro");
        const oldAudio = lastAudio();
        const requests = mediaRequests.length;
        await typeSearch("o");
        await typeSearch("ol");
        await typeSearch("old");
        assert.equal(oldAudio.paused, true);
        await debounceSearch();
        assert.equal(mediaRequests.length, requests + 1);
        await typeSearch("new");
        await debounceSearch();
        assert.ok(document.body.textContent.includes("Track 9"));
        assert.equal(activePage(), "1");
        releaseOld(mediaResult([track(8)], 1));
        await settle();
        assert.ok(document.body.textContent.includes("Track 9"), "Old search cannot overwrite newer results");
        await click("Intro");
        await click("Settings");
        assert.equal(lastAudio().paused, true, "Leaving Intros stops listening");
        await click("Intros");
        assert.ok(document.body.textContent.includes("Track 9"), "Returning keeps the latest results");
    } finally {
        app.unmount();
        mediaResponse = null;
    }
});

test("stop during page loading prevents resumed audio, and playback failures are visible", async () => {
    let releasePage;
    mediaResponse = async (url) => new URL(url, "http://localhost").searchParams.get("page") === "2"
        ? new Promise((resolve) => { releasePage = resolve; })
        : mediaResult([track(1)], 26);
    const app = await mountIntros();
    try {
        await click("Scan Intros");
        lastAudio().tick(15);
        await settle();
        const count = IntroAudio.instances.length;
        const scan = document.querySelector(".sweepers-scan");
        assert.equal(scan.textContent.trim(), "Stop");
        assert.ok(!scan.disabled, "Scan can be stopped while the next page is loading");
        await click("Stop");
        releasePage(mediaResult([track(26)], 26));
        await settle();
        assert.equal(IntroAudio.instances.length, count);
        IntroAudio.reject = true;
        await click("Intro");
        assert.ok(document.body.textContent.includes("Playback blocked"));
        assert.equal(lastAudio().paused, true);
    } finally {
        IntroAudio.reject = false;
        app.unmount();
        mediaResponse = null;
    }
});

test("empty intro pages are skipped and media failures stop scanning explicitly", async () => {
    mediaResponse = async (url) => {
        const page = Number(new URL(url, "http://localhost").searchParams.get("page"));
        if (page === 3) throw new Error("Page loading failed");
        return mediaResult([track(page, null)], 51);
    };
    const app = await mountIntros();
    try {
        const count = IntroAudio.instances.length;
        await click("Scan Intros");
        assert.equal(IntroAudio.instances.length, count);
        assert.ok(document.body.textContent.includes("Page loading failed"));
        const scan = document.querySelector(".sweepers-scan");
        assert.equal(scan.textContent.trim(), "Scan Intros");
        assert.equal(scan.getAttribute("aria-pressed"), "false");
    } finally {
        app.unmount();
        mediaResponse = null;
    }
});

test("unknown intro switch filters server-side, resets pages and removes newly tagged tracks", async () => {
    let tagged = false;
    mediaResponse = async (url) => {
        const query = new URL(url, "http://localhost").searchParams;
        if (query.get("unknown") === "1") {
            return mediaResult(tagged ? [] : [track(3, null)], tagged ? 0 : 1);
        }
        return mediaResult([track(1)], 51);
    };
    const app = await mountIntros();
    assert.ok(document.querySelector(".datatable-toolbar-top .sweepers-filters #unknown-intros")
        && !document.querySelector("#unknown-intros").closest(".dropdown-menu"), "The unknown filter is visible in the toolbar");
    const toggle = async (checked) => {
        const node = document.querySelector("#unknown-intros");
        node.checked = checked;
        node.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
    };
    try {
        assert.equal(document.querySelector('label[for="search"]'), null);
        assert.equal(document.querySelector("#search").getAttribute("aria-label"), "Search tracks");
        assert.ok([...document.querySelectorAll("strong")].some((node) => node.textContent === "empty"));
        await goToPage(2);
        await click("Intro");
        await toggle(true);
        assert.equal(lastAudio().paused, true);
        assert.ok(mediaRequests.at(-1).includes("unknown=1&page=1&per_page=25"));
        assert.equal(activePage(), "1");
        assert.equal(document.querySelectorAll(".datatable-toolbar-top .page-link").length, 1, "One result page");
        assert.ok(document.body.textContent.includes("Track 3"));
        await typeSearch("test");
        await debounceSearch();
        assert.ok(mediaRequests.at(-1).includes("search=test&unknown=1&page=1"));
        await click("Edit intro");
        await setInput("12");
        tagged = true;
        await save();
        assert.equal(document.querySelector("article"), null);
        assert.ok(document.body.textContent.includes("No records."));
        await toggle(false);
        assert.ok(!mediaRequests.at(-1).includes("unknown="));
        assert.ok(document.body.textContent.includes("Track 1"));
    } finally {
        app.unmount();
        mediaResponse = null;
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
        assert.equal(first.swap_from_playlist, false);
        assert.equal(field("Swap with another song from same playlist").checked, false);
        assert.ok(document.querySelector("section.card").textContent.includes("Improve Sweeper-to-Song Matching"));
        await change("Swap with another song from same playlist", true, true);
        await change("Also move or swap songs from sequential playlists", true, true);
        const replacementOnly = await submit();
        assert.equal(replacementOnly.swap_from_playlist, true);
        assert.equal(replacementOnly.swap_for_intro, false, "Replacement can be enabled without queued-song movement");
        assert.equal(replacementOnly.swap_sequential, true, "Sequential control is available for replacement alone");
        await change("Swap with another song from same playlist", false, true);
        assert.equal(first.swap_sequential, false);
        assert.deepEqual(first.excluded_playlist_ids, []);
        assert.deepEqual(first.playlist_ids, [], "No sweeper playlist by default");
        const openPicker = async (id) => {
            document.getElementById(id + "-toggle").click();
            await settle();
        };
        const playlistLabels = (prefix) => [...document.querySelectorAll(`input[id^="${prefix}"]`)]
            .map((node) => document.querySelector(`label[for="${node.id}"]`).textContent.trim());
        const check = async (id, checked = true) => {
            const pickerId = id.slice(0, id.lastIndexOf("-"));
            if (!document.getElementById(id)) await openPicker(pickerId);
            const node = document.getElementById(id);
            node.checked = checked;
            node.dispatchEvent(new Event("change", { bubbles: true }));
            await settle();
        };
        assert.ok([...document.querySelectorAll("legend.form-label")].some((node) => node.textContent === "Sweeper playlists"));
        assert.ok(document.querySelector("form.sweepers-settings").classList.contains("form"), "Settings use the native AzuraCast form styles");
        assert.equal(document.querySelectorAll(".sweepers-playlist-picker input[type=checkbox]").length, 0);
        await openPicker("sweepers-playlist");
        const addButton = document.getElementById("sweepers-playlist-toggle");
        assert.equal(addButton.textContent.trim(), "Add Playlist");
        assert.ok(addButton.classList.contains("btn-primary"), "Add button uses AzuraCast's primary style");
        assert.ok(addButton.classList.contains("btn-sm"), "Add button matches the compact playlist toolbar buttons");
        assert.ok(addButton.querySelector("svg path").getAttribute("d"), "Add button includes its plus icon");
        assert.deepEqual(playlistLabels("sweepers-playlist-"), ["Sweepers", "Christmas (scheduled)", "Retired (disabled)"],
            "Several sweeper playlists can be selected");
        assert.ok(addButton.classList.contains("dropdown-toggle"), "Button includes the native dropdown arrow");
        const choices = document.getElementById("sweepers-playlist-choices");
        assert.ok(choices.classList.contains("dropdown-menu"), "Selection uses the native dropdown styling");
        assert.equal(choices.querySelector('input[type="search"]'), null, "Playlist selection does not need search");
        assert.equal(choices.querySelector("button"), null, "Dropdown has no Save or Clear buttons");
        const writesBeforeSelection = writes.length;
        await check("sweepers-playlist-10");
        await check("sweepers-playlist-11");
        const songBadge = document.querySelector(".sweepers-playlist-picker .playlist-source-badge");
        const picker = addButton.closest(".sweepers-playlist-picker");
        assert.equal(picker.firstElementChild, songBadge.parentElement, "Selected tags stay above the button");
        assert.equal(picker.lastElementChild, addButton.parentElement, "Add button follows the selected tags");
        assert.ok(songBadge.classList.contains("gap-1"), "Labels use source badge spacing");
        assert.ok(songBadge.querySelector("svg.sm path"), "Song-based labels include the source icon");
        assert.equal(writes.length, writesBeforeSelection, "Selections update the form without autosaving");
        assert.ok(document.getElementById("sweepers-playlist-10").checked, "Selected playlist remains checked in the open dropdown");
        assert.equal(addButton.getAttribute("aria-expanded"), "true", "Checkbox changes keep the dropdown open");
        await check("sweepers-playlist-11", false);
        assert.equal(document.querySelector('[aria-label="Remove Christmas (scheduled)"]'), null, "Unchecking removes its tag immediately");
        await check("sweepers-playlist-11");
        document.querySelector('[aria-label="Remove Sweepers"]').click();
        await settle();
        assert.equal(document.getElementById("sweepers-playlist-10").checked, false, "Removing a tag unchecks its playlist");
        const escape = new Event("keydown", { bubbles: true });
        escape.key = "Escape";
        choices.dispatchEvent(escape);
        await settle();
        assert.equal(addButton.getAttribute("aria-expanded"), "false", "Escape closes the dropdown");
        assert.equal(document.getElementById("sweepers-playlist-choices"), null);
        await openPicker("sweepers-playlist");
        document.querySelector("h2").dispatchEvent(new Event("pointerdown", { bubbles: true }));
        await settle();
        assert.equal(addButton.getAttribute("aria-expanded"), "false", "Clicking outside closes the dropdown");
        assert.deepEqual((await submit()).playlist_ids, [11], "Removing a label updates the saved selection");
        await check("sweepers-playlist-10");
        assert.deepEqual((await submit()).playlist_ids, [11, 10], "All selected sweeper playlists are saved");
        assert.equal(first.sweeper_gap, 1, "One other sweeper before a repeat by default");
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
        assert.equal(
            field("Intro length tolerance").closest(".row").className,
            field("Handover margin").closest(".row").className,
            "Tolerance uses the same responsive row as handover margin"
        );
        assert.equal(
            field("Intro length tolerance").closest(".col-md-6").className,
            field("Handover margin").closest(".col-md-6").className,
            "Tolerance has the same narrow responsive column as handover margin"
        );
        assert.match(document.querySelector("section.card").textContent, /regular rotation chooses another fitting sweeper/);
        await openPicker("sweepers-jingle");
        assert.deepEqual(playlistLabels("sweepers-jingle-"), ["Station IDs (Jingle Mode)", "Promos"]);
        await check("sweepers-jingle-20");
        await check("sweepers-jingle-21");
        const groupBadge = document.querySelector('[aria-label="Remove Promos"]').parentElement;
        assert.notEqual(groupBadge.querySelector("path").getAttribute("d"), songBadge.querySelector("path").getAttribute("d"),
            "Playlist groups have a different source icon from song-based playlists");
        await change("Limit start delay", true, true);
        assert.equal(field("Maximum start delay").value, "10", "Maximum start delay shows its 10-second default");
        assert.equal(field("Early start").value, "2", "Early start shows its 2-second default");
        await change("Early start", "3.5");
        await change("Jingle gain", "70");
        await change("Other sweepers before a repeat", "2");
        await change("Move a song with an intro forward", true, true);
        await change("Also move or swap songs from sequential playlists", true, true);
        await change("Swap with another song from same playlist", true, true);
        await openPicker("sweepers-excluded");
        assert.deepEqual(playlistLabels("sweepers-excluded-"), ["Station IDs", "Promos"]);
        await check("sweepers-excluded-21");
        await change("Add reverb to the sweeper voice", true, true);
        assert.equal(field("Reverb intensity").value, "25");
        await change("Reverb intensity", "40");
        const settings = await submit();
        assert.equal(settings.reverb, true);
        assert.equal(settings.reverb_intensity, 40);
        assert.equal(settings.swap_for_intro, true);
        assert.equal(settings.swap_from_playlist, true);
        assert.equal(settings.swap_sequential, true);
        assert.equal(settings.sweeper_gap, 2);
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

test("settings tabs preserve edits, reveal invalid fields and share the native save button", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
    app.mount(document.getElementById("app"));
    try {
        await settle();
        const nav = document.querySelector('[aria-label="Sweeper settings"]');
        const tabs = [...nav.querySelectorAll('[role="tab"]')];
        assert.deepEqual(tabs.map((tab) => tab.textContent), ["General", "Spacing & Jingles", "Selection & Timing", "Audio & Preview"]);
        const panel = (key) => document.getElementById("sweepers-panel-" + key);
        assert.notEqual(panel("general").style.display, "none");
        assert.equal(panel("audio").style.display, "none");
        const select = async (index) => { tabs[index].click(); await settle(); };
        await select(1);
        const spacing = document.getElementById("sweepers-after-jingle");
        spacing.value = "7";
        spacing.dispatchEvent(new Event("input", { bubbles: true }));
        await select(3);
        assert.equal(panel("spacing").style.display, "none");
        assert.notEqual(panel("audio").style.display, "none");
        const duck = document.getElementById("sweepers-duck");
        duck.value = "-9";
        duck.dispatchEvent(new Event("input", { bubbles: true }));
        await select(0);
        assert.equal(spacing.value, "7", "Hidden panels keep unsaved edits");
        assert.ok(document.querySelector('[data-plugin-version]'), "Version remains outside panels");
        assert.equal(document.querySelector('[data-plugin-version]').closest('[role="tabpanel"]'), null);
        const save = document.querySelector('.sweepers-settings button[type="submit"]');
        assert.equal(document.querySelectorAll('.sweepers-settings button[type="submit"]').length, 1);
        assert.equal(save.closest('[role="tabpanel"]'), null);
        assert.ok(save.classList.contains("btn-lg"));
        assert.equal(save.parentElement.className, "buttons mt-3");
        const invalid = new Event("invalid", { bubbles: true, cancelable: true });
        let reported = false;
        duck.reportValidity = () => { reported = true; };
        duck.dispatchEvent(invalid);
        await settle();
        assert.equal(tabs[3].getAttribute("aria-selected"), "true", "Invalid hidden field opens its tab");
        assert.ok(reported, "Validation is displayed after revealing the field");
        const key = new Event("keydown", { bubbles: true, cancelable: true });
        key.key = "ArrowRight";
        tabs[3].dispatchEvent(key);
        await settle();
        assert.equal(tabs[0].getAttribute("aria-selected"), "true", "Keyboard navigation wraps");
        const form = document.querySelector("form.sweepers-settings");
        form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await settle();
        assert.equal(writes.at(-1).body.songs_after_jingle, 7);
        assert.equal(writes.at(-1).body.duck_db, -9, "One save includes edits from all tabs");
    } finally {
        app.unmount();
    }
});

test("module status tells when a one-time restart is still required", async () => {
    moduleState = { state: "restart", message: "The sweeper module is not running. Restart broadcasting once after installing the plugin." };
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        const status = document.querySelector('[data-module-status="restart"]');
        assert.ok(status, "Restart requirement is not shown");
        assert.ok(status.classList.contains("alert-warning"));
        assert.match(status.textContent, /Restart broadcasting once/);
        assert.equal(status.querySelector("button"), null, "Users without Broadcasting permission get no restart button");
        document.querySelector("section.card form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await settle();
        assert.match(document.querySelector('div.alert-info[role="status"]').textContent, /apply once the sweeper module runs/);
    } finally {
        app.unmount();
        moduleState = { state: "active", message: "The sweeper module is loaded and active on this station." };
    }
});

test("restart button restarts broadcasting through AzuraCast's API after confirmation", async () => {
    moduleState = { state: "outdated", message: "This station runs an older sweeper module than the installed plugin." };
    restartInfo = { url: "/api/station/1/restart", csrf: "api-token" };
    const confirms = [];
    globalThis.window.confirm = (message) => { confirms.push(message); return confirms.length > 1; };
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        const button = document.querySelector('[data-module-status="outdated"] button.btn-warning');
        assert.equal(button?.textContent.trim(), "Restart Broadcasting");
        await click("Restart Broadcasting");
        assert.equal(restarts.length, 0, "Cancelling the confirmation does not restart");
        await click("Restart Broadcasting");
        assert.equal(restarts.length, 1);
        assert.equal(restarts[0].method, "POST");
        assert.equal(restarts[0].headers["X-API-CSRF"], "api-token");
        assert.match(document.querySelector('div.alert-info[role="status"]').textContent, /Broadcasting restarted/);
    } finally {
        app.unmount();
        delete globalThis.window.confirm;
        restartInfo = null;
        moduleState = { state: "active", message: "The sweeper module is loaded and active on this station." };
    }
});

test("settings show the installed version and newer releases with their restart requirement", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        assert.equal(document.querySelector("[data-plugin-version]").textContent.trim(), "Plugin version 1.0.1 (main @ ecd7ff2)");
        assert.equal(document.querySelector("[data-plugin-update]"), null, "No update notice without a newer release");
    } finally {
        app.unmount();
    }
    const saved = releaseInfo;
    for (const [restart, text] of [
        ["not_required", /Version 1\.1\.0 is available\. It applies without restarting broadcasting\./],
        ["required", /requires restarting broadcasting once per station/],
        ["unknown", /Check the release notes/],
    ]) {
        releaseInfo = { ...saved, restart, latest: { version: "1.1.0", url: "https://github.com/RM-FM/azuracast-sweepers/releases/tag/1.1.0", restart } };
        const update = createApp(App, { base: "/sweepers/1", csrf: "session-token" });
        update.mount(document.querySelector("#app"));
        try {
            await settle();
            const notice = document.querySelector("[data-plugin-update]");
            assert.match(notice.textContent, text);
            assert.deepEqual([...notice.querySelectorAll("a")].map((link) => link.getAttribute("href")), [
                "https://github.com/RM-FM/azuracast-sweepers/releases/tag/1.1.0",
                "https://github.com/RM-FM/azuracast-sweepers#updating",
            ]);
        } finally {
            update.unmount();
        }
    }
    releaseInfo = saved;
});

test("playback timeline lists all tracks, highlights songs with a sweeper and explains the others", async () => {
    const app = createApp(App, { base: "/sweepers/1", csrf: "session-token", view: "history" });
    app.mount(document.querySelector("#app"));
    try {
        await settle();
        await settle();
        assert.equal(document.querySelector("form.sweepers-settings"), null);
        assert.equal(document.querySelector("#sweepers-history-heading").textContent, "Sweeper Playback Timeline");
        const rows = () => [...document.querySelectorAll("#app tbody tr")];
        const cell = (row, column) => row.querySelectorAll("td")[column];
        const parts = (row, column) => [...cell(row, column).querySelectorAll(".timeline-title, .timeline-artist, .timeline-moved, .timeline-reason, .timeline-unmoved")]
            .map((node) => node.textContent.replace(/\s+/g, " ").trim());
        const all = rows();
        assert.equal(all.length, 7, "All played tracks are listed");
        assert.deepEqual(all.map((row) => row.className),
            ["timeline-plain", "timeline-missed", "timeline-jingle", "timeline-played", "timeline-missed", "timeline-played", "timeline-plain"]);
        const tag = (row) => {
            const badge = cell(row, 2).querySelector(".badge");
            return badge ? [badge.textContent.trim(), [...badge.classList].find((name) => name.startsWith("text-bg-"))] : null;
        };
        assert.deepEqual(all.map(tag), [
            ["Upcoming", "text-bg-info"], ["Missed", "text-bg-danger"], null,
            ["Played", "text-bg-success"], ["Missed", "text-bg-danger"], ["Played", "text-bg-success"], null,
        ], "Green for played, red when a due sweeper could not play, no tag for intended skips");
        assert.deepEqual(parts(all[0], 3), ["Sweeper coming up"], "The current song's sweeper is still pending");
        assert.deepEqual(parts(all[1], 3), ["No intro end set", "Not moved forward: no queued song has a fitting intro"]);
        assert.deepEqual(parts(all[2], 1), ["Dropin 01", "RadioMonster.FM"]);
        assert.deepEqual(parts(all[2], 3), ["Jingle"]);
        assert.deepEqual(parts(all[3], 1), ["Voulez-Vous", "ABBA", "↑ Moved ahead of Bohemian Rhapsody"]);
        assert.equal(cell(all[3], 1).querySelector(".timeline-moved").getAttribute("title"), "Queen – Bohemian Rhapsody");
        assert.deepEqual(parts(all[3], 3), ["Dropin 03 (no ads)", "RadioMonster.FM"]);
        assert.equal(cell(all[3], 4).textContent.trim(), "12.3 s ✎", "The song's intro end is shown");
        assert.equal(cell(all[3], 5).textContent.trim(), "9.7 s");
        assert.equal(cell(all[1], 4).textContent.trim(), "Set ✎", "A missing intro end can be set");
        assert.equal(cell(all[2], 4).querySelector("button"), null, "Jingles have no intro to edit");
        assert.equal(cell(all[1], 5).textContent.trim(), "", "No start time without a sweeper");
        assert.deepEqual(parts(all[4], 3), ["Sweeper did not start"]);
        assert.deepEqual(parts(all[4], 1), ["I'm Your Man", "Wham!", "Replaced No Intro from the same playlist"]);
        assert.equal(cell(all[4], 1).querySelector(".timeline-moved").getAttribute("title"), "Original Artist – No Intro");
        assert.deepEqual(parts(all[5], 1), ["untagged.mp3"]);
        assert.deepEqual(parts(all[5], 3), ["Deleted file"]);
        assert.deepEqual(parts(all[6], 3), ["No intro"], "A 0 s intro means no intro, also in older entries");
        assert.equal(cell(all[6], 4).textContent.trim(), "0.0 s ✎");
        assert.equal(all[5].querySelector(".timeline-moved"), null, "Songs in their planned order are not marked");
        assert.deepEqual([...document.querySelectorAll("#app thead th")].map((th) => th.textContent.trim()),
            ["Date/Time (Station)", "Track", "Sweeper Status", "Sweeper or Reason", "Intro", "Started After"]);
        const text = document.querySelector("#app").textContent;
        assert.doesNotMatch(text, /All played tracks|newest first|last 300/);
        const query = () => new URL(historyRequests.at(-1), "http://localhost").searchParams;
        assert.equal(query().get("page"), "1");
        assert.equal(query().get("per_page"), "25");
        assert.equal(query().get("played"), null);
        assert.equal(Date.parse(query().get("end")) - Date.parse(query().get("start")), 86400000, "Last 24 hours by default");
        assert.ok(document.querySelector(".card-header .sweepers-range.btn-dark"), "The date range sits in the header");
        const only = document.getElementById("sweepers-only-played");
        assert.ok(only.closest(".datatable-toolbar-top"), "The filter sits in the table toolbar");
        only.checked = true;
        only.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
        assert.equal(query().get("played"), "1", "The server filters songs with a sweeper");
        assert.equal(rows().length, 2, "The filter shows only songs with a sweeper");
        assert.ok(rows().every((row) => row.className === "timeline-played"));
        only.checked = false;
        only.dispatchEvent(new Event("change", { bubbles: true }));
        await settle();
        const requests = historyRequests.length;
        const searchBox = document.getElementById("sweepers-history-search");
        searchBox.value = "abba";
        searchBox.dispatchEvent(new Event("input", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 330));
        await settle();
        assert.equal(historyRequests.length, requests + 1, "Search is debounced");
        assert.equal(query().get("search"), "abba");
        searchBox.value = "";
        searchBox.dispatchEvent(new Event("input", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 330));
        await settle();
        const yesterday = [...document.querySelectorAll(".dp-preset")].find((node) => node.textContent === "Yesterday");
        yesterday.dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        const chosen = [query().get("start"), query().get("end")];
        assert.equal(Date.parse(chosen[1]) - Date.parse(chosen[0]), 86399999, "A preset selects a whole station day");
        document.querySelector('.datatable-toolbar-top button[title="Refresh rows"]').dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        assert.deepEqual([query().get("start"), query().get("end")], chosen, "Refresh keeps a chosen range");
        cell(rows()[1], 4).querySelector("button").dispatchEvent(new Event("click", { bubbles: true }));
        await settle();
        assert.deepEqual(mediaLookups, ["/sweepers/1/media?id=5"]);
        assert.equal(document.querySelector("article h2").textContent, "queen.mp3", "The intro editor opens on that song");
        assert.equal(document.querySelector("#search").value, "queen.mp3");
        assert.equal(input().value, "");
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
        assert.match(document.querySelector(".sweepers-preview").textContent, /Choose a sweeper playlist in General/);
        assert.ok(button().disabled, "Nothing to play without a sweeper playlist");
        const playlist = async (id) => {
            if (!document.getElementById("sweepers-playlist-choices")) {
                document.getElementById("sweepers-playlist-toggle").click();
                await settle();
            }
            const node = document.getElementById(`sweepers-playlist-${id}`);
            node.checked = true;
            node.dispatchEvent(new Event("change", { bubbles: true }));
            await settle();
        };
        await playlist(10);
        await playlist(11);
        await settle();
        assert.equal(previewRequests.at(-1), "/sweepers/1/media?playlist=10,11", "Unsaved playlist choice loads all their sweepers");
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
        assert.equal(context.gains[0].gain.target, 0.203 * 0.25, "Switching the reverb on is heard right away (25%)");
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
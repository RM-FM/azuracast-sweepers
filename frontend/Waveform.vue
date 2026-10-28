<template>
    <div ref="container" class="waveform" aria-label="Original audio waveform" />
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <div class="d-flex flex-wrap align-items-center gap-2 my-3">
        <button class="btn btn-secondary" type="button" :disabled="!ready" :aria-pressed="playing" @click="togglePlayback">{{ playing ? 'Stop' : 'Play' }}</button>
        <slot name="controls" :ready="ready" />
        <label class="d-flex align-items-center gap-2 mb-0">
            Zoom
            <input v-model.number="zoom" class="form-range mb-0" type="range" min="0" max="150" :disabled="!ready">
        </label>
        <span class="text-muted">{{ position.toFixed(2) }} s</span>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import WaveSurfer from "wavesurfer.js";
import RegionsPlugin from "wavesurfer.js/dist/plugins/regions.js";

const props = defineProps({
    audioUrl: { type: String, required: true },
    waveformUrl: { type: String, required: true },
    intro: { type: Number, default: null },
});
const emit = defineEmits(["position", "intro"]);
const container = ref(null);
const position = ref(0);
const zoom = ref(0);
const ready = ref(false);
const playing = ref(false);
const error = ref("");
let player = null;
let regions = null;
let disposed = false;

function updateMarker() {
    if (!ready.value) return;
    regions.clearRegions();
    if (props.intro !== null) {
        regions.addRegion({
            id: "intro_end",
            start: props.intro,
            content: "Intro End",
            color: getComputedStyle(container.value).getPropertyValue("--bs-warning").trim() || "#ef9b20",
            drag: true,
            resize: false,
        });
    }
}

onMounted(async () => {
    regions = RegionsPlugin.create();
    const styles = getComputedStyle(container.value);
    player = WaveSurfer.create({
        container: container.value,
        waveColor: styles.getPropertyValue("--bs-primary").trim() || "#2196f3",
        progressColor: styles.getPropertyValue("--bs-info").trim() || "#4081cf",
        cursorColor: styles.getPropertyValue("--bs-body-color").trim() || "#222",
        plugins: [regions],
    });
    player.on("timeupdate", (time) => {
        position.value = time;
        emit("position", time);
    });
    player.on("interaction", (time) => {
        position.value = time;
        emit("position", time);
    });
    regions.on("region-updated", (region) => emit("intro", Math.round(region.start * 100) / 100));
    player.on("ready", () => {
        ready.value = true;
        updateMarker();
    });
    player.on("play", () => { playing.value = true; });
    player.on("pause", () => { playing.value = false; });
    player.on("finish", () => { playing.value = false; });
    player.on("error", (exception) => {
        playing.value = false;
        error.value = exception.message;
    });

    try {
        let peaks;
        try {
            const response = await fetch(props.waveformUrl, { credentials: "same-origin" });
            if (response.ok) peaks = (await response.json())?.data;
        } catch {
            peaks = undefined;
        }
        if (!disposed) await player.load(props.audioUrl, peaks);
    } catch (exception) {
        if (!disposed) error.value = exception.message;
    }
});

watch(() => props.intro, updateMarker);
watch(zoom, (value) => player?.zoom(value));

async function playAt(time) {
    if (ready.value) {
        error.value = "";
        try {
            player.setTime(Math.min(player.getDuration(), Math.max(0, time)));
            await player.play();
        } catch (exception) {
            error.value = exception.message;
        }
    }
}

async function togglePlayback() {
    if (!ready.value) return;
    error.value = "";
    try {
        if (playing.value) player.pause();
        else await player.play();
    } catch (exception) {
        error.value = exception.message;
    }
}

onBeforeUnmount(() => {
    disposed = true;
    player?.destroy();
});

defineExpose({ playAt, ready, pause: () => player?.pause() });
</script>

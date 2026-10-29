<template>
    <div class="waveform-editor">
    <div ref="container" class="waveform" aria-label="Original audio waveform" />
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <div class="row mt-3 align-items-center waveform-levels">
        <div class="col-md-7">
            <label class="d-flex align-items-center gap-3 mb-0">
                <span class="text-nowrap">Waveform Zoom</span>
                <input v-model.number="zoom" class="form-range w-100 mb-0" aria-label="Waveform Zoom" type="range" min="0" max="256" :disabled="!ready">
            </label>
        </div>
        <div class="col-md-5">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-link p-0" type="button" :disabled="!ready" :title="muted ? 'Unmute' : 'Mute'" :aria-label="muted ? 'Unmute' : 'Mute'" :aria-pressed="muted" @click="muted = !muted">
                    <Icon :name="muted || volume === 0 ? 'volumeOff' : 'volumeUp'" />
                </button>
                <input v-model.number="volume" class="form-range w-100 mb-0" aria-label="Volume" title="Volume" type="range" min="0" max="100" step="1" :disabled="!ready">
            </div>
        </div>
    </div>
    <div class="buttons mt-3 d-flex flex-wrap align-items-center gap-2 waveform-transport">
        <div class="btn-group btn-group-sm">
            <button class="btn btn-light" type="button" title="Play" aria-label="Play" :disabled="!ready" :aria-pressed="playing" @click="play"><Icon name="play" /></button>
            <button class="btn btn-dark" type="button" title="Stop" aria-label="Stop" :disabled="!ready" @click="pause"><Icon name="stop" /></button>
        </div>
        <slot name="controls" :ready="ready" />
        <span class="text-muted ms-auto" aria-label="Playback position">{{ position.toFixed(2) }} s</span>
    </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import WaveSurfer from "wavesurfer.js";
import RegionsPlugin from "wavesurfer.js/dist/plugins/regions.js";
import TimelinePlugin from "wavesurfer.js/dist/plugins/timeline.js";
import Icon from "./Icon.vue";

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
const volume = ref(55);
const audioVolume = computed(() => Math.expm1(volume.value / 100) / Math.expm1(1));
const muted = ref(false);
const error = ref("");
let player = null;
let regions = null;
let disposed = false;
let marker = null;

function updateMarker() {
    if (!ready.value) return;
    if (props.intro === null) {
        marker?.remove();
        marker = null;
    } else if (marker) {
        if (marker.start !== props.intro) marker.setOptions({ start: props.intro, end: props.intro });
    } else {
        marker = regions.addRegion({
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
        height: 128,
        plugins: [regions, TimelinePlugin.create()],
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
        player.setVolume(audioVolume.value);
        player.setMuted(muted.value);
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
watch(audioVolume, (value) => player?.setVolume(value));
watch(muted, (value) => player?.setMuted(value));

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

async function play() {
    if (!ready.value) return;
    error.value = "";
    try {
        await player.play();
    } catch (exception) {
        error.value = exception.message;
    }
}

function pause() {
    player?.pause();
}

onBeforeUnmount(() => {
    disposed = true;
    player?.destroy();
});

defineExpose({ playAt, ready, pause });
</script>

<template>
    <div ref="container" class="waveform" aria-label="Original audio waveform" />
    <p v-if="error" role="alert">{{ error }}</p>
    <div class="toolbar">
        <button type="button" :disabled="!ready" @click="player?.playPause()">Play / Pause</button>
        <button type="button" :disabled="!ready" @click="player?.pause()">Stop</button>
        <label>
            Zoom
            <input v-model.number="zoom" type="range" min="0" max="150" :disabled="!ready">
        </label>
        <span>{{ position.toFixed(2) }} s</span>
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
const error = ref("");
let player = null;
let regions = null;
let disposed = false;

function updateMarker() {
    if (!ready.value) {
        return;
    }
    regions.clearRegions();
    if (props.intro !== null) {
        regions.addRegion({
            id: "intro_end",
            start: props.intro,
            content: "Intro End",
            color: "#ef9b20",
            drag: true,
            resize: false,
        });
    }
}

onMounted(async () => {
    regions = RegionsPlugin.create();
    player = WaveSurfer.create({
        container: container.value,
        waveColor: "#2196f3",
        progressColor: "#4081cf",
        cursorColor: "#222",
        plugins: [regions],
    });
    player.on("timeupdate", (time) => {
        position.value = time;
        emit("position", time);
    });
    regions.on("region-updated", (region) => emit("intro", Math.round(region.start * 100) / 100));
    player.on("ready", () => {
        ready.value = true;
        updateMarker();
    });

    try {
        let peaks;
        try {
            const response = await fetch(props.waveformUrl, { credentials: "same-origin" });
            if (response.ok) {
                peaks = (await response.json())?.data;
            }
        } catch {
            peaks = undefined;
        }
        if (!disposed) {
            await player.load(props.audioUrl, peaks);
        }
    } catch (exception) {
        if (!disposed) {
            error.value = exception.message;
        }
    }
});

watch(() => props.intro, updateMarker);
watch(zoom, (value) => player?.zoom(value));

function playAt(time) {
    if (ready.value) {
        player.setTime(Math.min(player.getDuration(), Math.max(0, time)));
        void player.play();
    }
}

onBeforeUnmount(() => {
    disposed = true;
    player?.destroy();
});

defineExpose({ playAt });
</script>

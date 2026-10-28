<template>
    <div class="sweepers-playlist-picker">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span v-for="playlist in selected" :key="playlist.id" class="playlist-source-badge badge text-bg-secondary d-inline-flex align-items-center gap-1">
                <Icon v-if="sourceIcon(playlist)" :name="sourceIcon(playlist)" class="sm" />
                <span class="text-truncate">{{ label(playlist) }}</span>
                <button class="btn-close btn-close-white" type="button" :aria-label="'Remove ' + label(playlist)" @click="remove(playlist.id)" />
            </span>
            <button
                :id="id + '-toggle'"
                ref="toggle"
                class="btn btn-primary"
                type="button"
                :aria-expanded="open"
                :aria-controls="id + '-choices'"
                @click="open = !open"
            ><Icon name="add" /><span>Add Playlist</span></button>
        </div>
        <div v-if="open" :id="id + '-choices'" class="border rounded p-2 mt-2" @keydown.esc.stop.prevent="close">
            <input ref="searchInput" v-model="search" class="form-control form-control-sm mb-2" type="search" :aria-label="'Search ' + title" placeholder="Search playlists" @keydown.enter.prevent>
            <div class="sweepers-playlist-options" role="group" :aria-label="title">
                <button v-for="playlist in available" :key="playlist.id" :id="id + '-' + playlist.id" class="btn btn-sm btn-outline-secondary d-block w-100 text-start mb-1" type="button" @click="add(playlist.id)">
                    {{ label(playlist) }}
                </button>
                <p v-if="!available.length" class="text-muted small mb-0">{{ search.trim() ? "No matching playlists." : "All playlists selected." }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from "vue";
import Icon from "./Icon.vue";

const sourceIcons = { songs: "libraryMusic", playlists: "queueMusic", remote_url: "public", requests: "people" };
const sourceIcon = (playlist) => sourceIcons[playlist.source];

const props = defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    modelValue: { type: Array, required: true },
    playlists: { type: Array, required: true },
    label: { type: Function, default: (playlist) => playlist.name },
});
const emit = defineEmits(["update:modelValue"]);
const open = ref(false);
const search = ref("");
const searchInput = ref(null);
const toggle = ref(null);
const selected = computed(() => props.playlists.filter((playlist) => props.modelValue.includes(playlist.id)));
const available = computed(() => props.playlists.filter((playlist) =>
    !props.modelValue.includes(playlist.id)
    && props.label(playlist).toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())
));
watch(open, async (value) => {
    search.value = "";
    if (value) {
        await nextTick();
        searchInput.value?.focus();
    }
});

async function add(id) {
    emit("update:modelValue", [...props.modelValue, id]);
    search.value = "";
    await nextTick();
    searchInput.value?.focus();
}

function remove(id) {
    emit("update:modelValue", props.modelValue.filter((value) => value !== id));
}

function close() {
    open.value = false;
    toggle.value?.focus();
}
</script>

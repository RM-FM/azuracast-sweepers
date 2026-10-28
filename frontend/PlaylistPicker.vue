<template>
    <div ref="picker" class="sweepers-playlist-picker" @keydown.esc.stop.prevent="close">
        <div v-if="selected.length" class="sweepers-playlist-selected d-flex flex-wrap align-items-center gap-2 mb-2">
            <span v-for="playlist in selected" :key="playlist.id" class="playlist-source-badge badge text-bg-secondary d-inline-flex align-items-center gap-1">
                <Icon v-if="sourceIcon(playlist)" :name="sourceIcon(playlist)" class="sm" />
                <span class="text-truncate">{{ label(playlist) }}</span>
                <button class="btn-close btn-close-white" type="button" :aria-label="'Remove ' + label(playlist)" @click="remove(playlist.id)" />
            </span>
        </div>
        <div class="dropdown">
            <button
                :id="id + '-toggle'"
                ref="toggle"
                class="btn btn-sm btn-primary dropdown-toggle"
                type="button"
                :aria-expanded="open"
                :aria-controls="id + '-choices'"
                @click="open = !open"
            ><Icon name="add" /><span>Add Playlist</span></button>
            <div v-if="open" :id="id + '-choices'" class="sweepers-playlist-choices dropdown-menu show p-3">
                <div class="sweepers-playlist-options" role="group" :aria-label="title">
                    <div v-for="playlist in playlists" :key="playlist.id" class="form-check mb-2">
                        <input :id="id + '-' + playlist.id" class="form-check-input" type="checkbox" :checked="modelValue.includes(playlist.id)" @change="setSelected(playlist.id, $event.target.checked)">
                        <label class="form-check-label" :for="id + '-' + playlist.id">{{ label(playlist) }}</label>
                    </div>
                    <p v-if="!playlists.length" class="text-muted small mb-0">No playlists available.</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
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
const picker = ref(null);
const toggle = ref(null);
const selected = computed(() => props.playlists.filter((playlist) => props.modelValue.includes(playlist.id)));
watch(open, async (value) => {
    if (value) {
        await nextTick();
        picker.value?.querySelector('input[type="checkbox"]')?.focus();
    }
});

function setSelected(id, checked) {
    if (checked && !props.modelValue.includes(id)) {
        emit("update:modelValue", [...props.modelValue, id]);
    } else if (!checked) {
        remove(id);
    }
}

function remove(id) {
    emit("update:modelValue", props.modelValue.filter((value) => value !== id));
}

function close() {
    open.value = false;
    toggle.value?.focus();
}

function outsideClick(event) {
    if (open.value && !picker.value?.contains(event.target)) open.value = false;
}

onMounted(() => document.addEventListener("pointerdown", outsideClick));
onUnmounted(() => document.removeEventListener("pointerdown", outsideClick));
</script>

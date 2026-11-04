<template>
    <div class="sweepers-links">
        <div class="row g-3 mb-3">
            <div class="form-group col-12 col-md-6">
                <label class="form-label" for="sweepers-link-window">Keep linked tracks apart (minutes)</label>
                <input id="sweepers-link-window" :value="window" class="form-control" type="number" min="0" max="1440" step="1" required @input="emit('update:window', Number($event.target.value))">
                <div class="form-text">Linked tracks never play within this time of each other. 0 = they only avoid each other while one of them is coming up.</div>
            </div>
        </div>
        <p v-if="error" class="text-danger small">{{ error }}</p>
        <div v-if="groups.length" class="accordion sweepers-link-groups mb-3">
            <div v-for="(group, index) in groups" :key="index" class="accordion-item" :data-link-group="index">
                <h3 class="accordion-header">
                    <button :id="'sweepers-link-toggle-' + index" class="accordion-button" :class="{ collapsed: !open.has(index) }" type="button" :aria-expanded="open.has(index)" :aria-controls="'sweepers-link-' + index" @click="toggle(index)">
                        <span class="flex-fill">{{ group.name || "Unnamed group" }}</span>
                        <span class="sweepers-rule-summary small me-3">{{ summary(group) }}</span>
                    </button>
                </h3>
                <div v-show="open.has(index)" :id="'sweepers-link-' + index" class="accordion-collapse" role="region" :aria-labelledby="'sweepers-link-toggle-' + index">
                    <div class="accordion-body">
                        <div class="form-group mb-3">
                            <label class="form-label" :for="'sweepers-link-name-' + index">Name</label>
                            <input :id="'sweepers-link-name-' + index" v-model="group.name" class="form-control" type="text" maxlength="100">
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span v-for="id in group.media_ids" :key="id" class="badge text-bg-secondary d-inline-flex align-items-center gap-1" :data-link-member="id">
                                <span>{{ kind(id) }}</span>
                                <span class="text-truncate">{{ label(id) }}</span>
                                <button class="btn-close btn-close-white" type="button" :aria-label="'Remove ' + label(id)" @click="removeMember(index, id)" />
                            </span>
                        </div>
                        <div v-if="group.media_ids.length < 2" class="form-text text-warning">Add at least two tracks.</div>
                        <div v-if="randomJingles(group).length" class="form-text">
                            {{ randomJingles(group).join(", ") }}: Random-order playlist. AzuraCast cannot skip a jingle there, so a linked jingle is swapped for another one when it comes up too soon.
                        </div>
                        <div class="mt-3">
                            <label class="form-label" :for="'sweepers-link-search-' + index">Add tracks</label>
                            <input :id="'sweepers-link-search-' + index" v-model="search[index]" class="form-control" type="search" placeholder="Search sweepers and jingles">
                            <ul v-if="search[index]" class="list-unstyled mt-2 mb-0 sweepers-link-results">
                                <li v-for="candidate in matches(index)" :key="candidate.id">
                                    <button type="button" class="btn btn-sm btn-link px-0 text-start" @click="addMember(index, candidate.id)"><Icon name="add" /><span>{{ kind(candidate.id) }}: {{ label(candidate.id) }}</span></button>
                                </li>
                                <li v-if="!matches(index).length" class="text-muted small">No unlinked sweeper or jingle matches.</li>
                            </ul>
                        </div>
                        <div class="buttons mt-3">
                            <button type="button" class="btn btn-sm btn-danger" :aria-label="'Remove ' + (group.name || 'unnamed') + ' link group'" @click="removeGroup(index)"><Icon name="delete" /><span>Remove Group</span></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="text-muted small">No linked tracks yet.</p>
        <div class="buttons">
            <button type="button" class="btn btn-sm btn-primary" @click="addGroup"><Icon name="add" /><span>Add Link Group</span></button>
            <button type="button" class="btn btn-sm btn-secondary" :disabled="!candidates.length" @click="suggestions = suggest()"><Icon name="search" /><span>Suggest Links</span></button>
        </div>
        <div v-if="suggestions" class="mt-3 sweepers-link-suggestions">
            <p v-if="!suggestions.length" class="text-muted small mb-0">No unlinked tracks share a title.</p>
            <template v-else>
                <p class="small mb-2">Tracks with the same title once case, punctuation and version labels are ignored:</p>
                <ul class="list-unstyled mb-2">
                    <li v-for="(suggestion, index) in suggestions" :key="suggestion.key" class="d-flex align-items-center gap-2 mb-1">
                        <span class="flex-fill small">{{ suggestion.name }}: {{ suggestion.media_ids.map((id) => kind(id) + " " + label(id)).join(", ") }}</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="acceptSuggestion(index)">Link</button>
                    </li>
                </ul>
                <button type="button" class="btn btn-sm btn-primary" @click="acceptAll">Link All</button>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from "vue";
import Icon from "./Icon.vue";
import { suggestLinks } from "./links.js";

const props = defineProps({
    groups: { type: Array, required: true },
    window: { type: Number, required: true },
    candidates: { type: Array, required: true },
    error: { type: String, default: "" },
});
const emit = defineEmits(["update:window"]);
const open = ref(new Set());
const search = reactive({});
const suggestions = ref(null);
const byId = computed(() => new Map(props.candidates.map((candidate) => [candidate.id, candidate])));
const linked = computed(() => new Set(props.groups.flatMap((group) => group.media_ids)));

function label(id) {
    const candidate = byId.value.get(id);
    if (!candidate) return `Track ${id} (no longer in a sweeper or jingle playlist)`;
    return candidate.title || candidate.path.split("/").pop();
}

function kind(id) {
    const candidate = byId.value.get(id);
    if (!candidate) return "Track";
    return candidate.sweeper && candidate.jingle ? "Sweeper & Jingle" : candidate.sweeper ? "Sweeper" : "Jingle";
}

function summary(group) {
    const sweepers = group.media_ids.filter((id) => byId.value.get(id)?.sweeper).length;
    const jingles = group.media_ids.filter((id) => byId.value.get(id)?.jingle).length;
    return `${sweepers} sweeper${sweepers === 1 ? "" : "s"} · ${jingles} jingle${jingles === 1 ? "" : "s"}`;
}

function randomJingles(group) {
    return group.media_ids
        .filter((id) => byId.value.get(id)?.playlists.some((playlist) => playlist.random))
        .map((id) => label(id));
}

function matches(index) {
    const query = (search[index] ?? "").trim().toLowerCase();
    return props.candidates
        .filter((candidate) => !linked.value.has(candidate.id))
        .filter((candidate) => `${candidate.title ?? ""} ${candidate.artist ?? ""} ${candidate.path}`.toLowerCase().includes(query))
        .slice(0, 25);
}

function toggle(index) {
    const next = new Set(open.value);
    if (!next.delete(index)) next.add(index);
    open.value = next;
}

function addGroup() {
    props.groups.push({ name: "", media_ids: [] });
    open.value = new Set([...open.value, props.groups.length - 1]);
}

function removeGroup(index) {
    props.groups.splice(index, 1);
    open.value = new Set([...open.value].filter((item) => item !== index).map((item) => item > index ? item - 1 : item));
}

function addMember(index, id) {
    props.groups[index].media_ids.push(id);
    if (!props.groups[index].name) props.groups[index].name = label(id);
    search[index] = "";
}

function removeMember(index, id) {
    props.groups[index].media_ids = props.groups[index].media_ids.filter((item) => item !== id);
}

function suggest() {
    return suggestLinks(props.candidates.filter((candidate) => !linked.value.has(candidate.id)));
}

function acceptSuggestion(index) {
    const [suggestion] = suggestions.value.splice(index, 1);
    props.groups.push({ name: suggestion.name, media_ids: suggestion.media_ids });
}

function acceptAll() {
    for (const suggestion of suggestions.value) props.groups.push({ name: suggestion.name, media_ids: suggestion.media_ids });
    suggestions.value = [];
}
</script>

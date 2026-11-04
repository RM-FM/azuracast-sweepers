<template>
    <div class="sweepers-route row g-3">
        <div class="col-12 col-lg-6">
            <span class="form-label d-block">Sweeper pool</span>
            <PlaylistPicker :id="'sweepers-route-pool-' + id" v-model="route.playlist_ids" :title="title + ' sweeper pool'" :playlists="playlists" :label="label" />
            <div class="form-text">{{ poolHelp }} Disabled playlists are skipped.</div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="row g-3">
                <fieldset class="form-group col-12 mb-0" :class="{ 'col-sm-6': route.key !== 'default' }">
                    <legend class="form-label">Placement</legend>
                    <div class="form-check form-check-inline">
                        <input :id="'sweepers-placement-intro-' + id" v-model="route.pre_roll" class="form-check-input" type="radio" :name="'sweepers-placement-' + id" :value="false">
                        <label class="form-check-label" :for="'sweepers-placement-intro-' + id">Intro only</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input :id="'sweepers-placement-preroll-' + id" v-model="route.pre_roll" class="form-check-input" type="radio" :name="'sweepers-placement-' + id" :value="true">
                        <label class="form-check-label" :for="'sweepers-placement-preroll-' + id">Allow pre-roll</label>
                    </div>
                    <div class="form-text">Intro only plays sweepers over the song intro. Allow pre-roll also plays a sweeper before the song when it is too long for the intro.</div>
                </fieldset>
                <fieldset v-if="route.key !== 'default'" class="form-group col-12 col-sm-6 mb-0">
                    <legend class="form-label">Song spacing</legend>
                    <div class="form-check form-switch">
                        <input :id="'sweepers-follow-spacing-' + id" v-model="route.follow_spacing" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" :for="'sweepers-follow-spacing-' + id">Follow song spacing</label>
                    </div>
                    <div class="form-check form-switch">
                        <input :id="'sweepers-counts-spacing-' + id" v-model="route.counts_for_spacing" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" :for="'sweepers-counts-spacing-' + id">Delay station sweepers</label>
                    </div>
                    <div class="form-text">Follow song spacing applies the Spacing &amp; Jingles rules to these sweepers. Delay station sweepers lets the next station sweeper wait after one of these, as after any sweeper.</div>
                </fieldset>
                <div v-if="route.pre_roll && route.key !== 'listener_request'" class="col-12 mt-2">
                    <div class="alert alert-warning mb-0" role="note">Song matching is bypassed for this rule. Songs keep their place; when a sweeper cannot fit over the intro, it starts before the song instead.</div>
                </div>
            </div>
            <div v-if="route.key !== 'default'" class="row g-3 mt-0">
                <div class="form-group col-12 col-sm-6 mb-0">
                    <label class="form-label" :for="'sweepers-cooldown-songs-' + id">Songs between sweepers</label>
                    <input :id="'sweepers-cooldown-songs-' + id" v-model.number="route.cooldown_songs" class="form-control" type="number" min="0" max="1000" step="1" required>
                    <div class="form-text">Songs that must play after the last song with a sweeper from this rule. 0 = no song limit.</div>
                </div>
                <div class="form-group col-12 col-sm-6 mb-0">
                    <label class="form-label" :for="'sweepers-cooldown-minutes-' + id">Minutes between sweepers</label>
                    <input :id="'sweepers-cooldown-minutes-' + id" v-model.number="route.cooldown_minutes" class="form-control" type="number" min="0" max="10080" step="1" required>
                    <div class="form-text">Minutes since this rule's last sweeper. 0 = no time limit.</div>
                </div>
                <div v-if="route.cooldown_songs > 0 && route.cooldown_minutes > 0" class="form-text col-12 mt-1">With both limits set, whichever is reached first allows the next sweeper.</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import PlaylistPicker from "./PlaylistPicker.vue";

const props = defineProps({
    route: { type: Object, required: true },
    title: { type: String, required: true },
    playlists: { type: Array, required: true },
    label: { type: Function, required: true },
});

const id = computed(() => props.route.key.replace(/[^a-z0-9_-]/gi, "-"));
const poolHelp = computed(() => ({
    default: "Advanced playlists with sweepers for every song without a more specific rule.",
    listener_request: "Advanced playlists with sweepers for listener requests. Leave empty to treat requests like other songs.",
}[props.route.key] ?? "Advanced playlists with sweepers for songs from this playlist. Leave empty for no sweepers on these songs."));
</script>

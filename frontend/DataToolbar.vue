<template>
    <div ref="root" class="datatable-toolbar-top card-body">
        <div class="row align-items-center">
            <div class="col-xl-6 col-lg-5 col-md-12 col-sm-12">
                <Pagination v-if="perPage > 0" :page="page" :total="total" :per-page="perPage" :label="label" @change="emit('page', $event)" />
            </div>
            <div class="col-xl-6 col-lg-7 col-md-12 col-sm-12 d-flex my-2 sweepers-toolbar-controls">
                <div class="flex-fill sweepers-toolbar-search">
                    <div class="input-group">
                        <span class="input-group-text"><Icon name="search" /></span>
                        <input
                            :id="searchId"
                            :value="search"
                            class="form-control"
                            type="search"
                            placeholder="Search"
                            :aria-label="searchLabel"
                            @input="emit('update:search', $event.target.value)"
                        >
                    </div>
                </div>
                <div v-if="$slots.filters" class="sweepers-filters">
                    <slot name="filters" />
                </div>
                <div class="flex-shrink-1">
                    <div class="btn-group actions">
                        <button type="button" class="btn btn-secondary" title="Refresh rows" aria-label="Refresh rows" @click="emit('refresh')">
                            <Icon name="refresh" />
                        </button>
                        <div class="dropdown btn-group" role="group">
                            <button
                                type="button"
                                class="btn btn-secondary dropdown-toggle"
                                :class="{ show: open === 'perPage' }"
                                title="Items per page"
                                :aria-expanded="open === 'perPage'"
                                @click="toggle('perPage')"
                            >
                                <span>{{ perPageLabel(perPage) }}</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" :class="{ show: open === 'perPage' }">
                                <li v-for="option in pageSizes" :key="option">
                                    <button type="button" class="dropdown-item" :class="{ active: option === perPage }" @click="choosePerPage(option)">
                                        {{ perPageLabel(option) }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import Icon from "./Icon.vue";
import Pagination from "./Pagination.vue";

defineProps({
    search: { type: String, required: true },
    searchId: { type: String, default: undefined },
    searchLabel: { type: String, default: "Search" },
    page: { type: Number, required: true },
    perPage: { type: Number, required: true },
    total: { type: Number, required: true },
    label: { type: String, default: "Pages" },
});
const emit = defineEmits(["update:search", "update:perPage", "page", "refresh"]);
// Same choices as AzuraCast's data tables; 0 shows all rows.
const pageSizes = [10, 25, 50, 100, 250, 500, 0];
const root = ref(null);
const open = ref(null);

function perPageLabel(size) {
    return size === 0 ? "All" : String(size);
}

function toggle(menu) {
    open.value = open.value === menu ? null : menu;
}

function choosePerPage(size) {
    open.value = null;
    emit("update:perPage", size);
}

function closeOutside(event) {
    if (open.value && !root.value?.contains(event.target)) open.value = null;
}

onMounted(() => document.addEventListener("click", closeOutside));
onUnmounted(() => document.removeEventListener("click", closeOutside));
</script>

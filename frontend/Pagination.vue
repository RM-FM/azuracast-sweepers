<template>
    <nav class="d-flex" :aria-label="label">
        <ul class="pagination mb-0">
            <li v-for="item in items" :key="item.key" class="page-item" :class="{ active: item.page === page, disabled: !item.page }">
                <a
                    class="page-link"
                    href="#"
                    :aria-current="item.page === page ? 'page' : undefined"
                    @click.prevent="item.page && setPage(item.page)"
                >{{ item.page ?? "..." }}</a>
            </li>
        </ul>
        <div v-if="pageCount > 10" class="input-group input-group-sm ms-2">
            <input
                v-model.number="inputPage"
                type="number"
                min="1"
                :max="pageCount"
                step="1"
                class="form-control rounded-start-2"
                aria-label="Page"
                style="max-width: 60px;"
                @keydown.enter.prevent="setPage(inputPage)"
            >
            <button class="btn btn-outline-secondary rounded-end-2" type="button" @click="setPage(inputPage)">Go</button>
        </div>
    </nav>
</template>

<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
    page: { type: Number, required: true },
    total: { type: Number, required: true },
    perPage: { type: Number, required: true },
    label: { type: String, default: "Pages" },
});
const emit = defineEmits(["change"]);
const pageSpace = 1;
const pageCount = computed(() => props.perPage > 0 ? Math.max(1, Math.ceil(props.total / props.perPage)) : 1);
const inputPage = ref(props.page);
watch(() => props.page, (page) => { inputPage.value = page; });

// Same page selection as AzuraCast's Pagination component.
const items = computed(() => {
    const page = props.page;
    let left = Math.max(1, page - pageSpace);
    if (left - 1 === 2) left--;
    let right = Math.min(page + pageSpace, pageCount.value);
    if (pageCount.value - right === 2) right++;
    const items = [];
    if (page >= pageSpace + 2) items.push({ key: "first", page: 1 });
    if (page >= pageSpace + 4) items.push({ key: "first-gap", page: null });
    for (let number = left; number <= right; number++) items.push({ key: number, page: number });
    if (page < pageCount.value - (pageSpace + 2)) items.push({ key: "last-gap", page: null });
    if (page <= pageCount.value - (pageSpace + 1)) items.push({ key: "last", page: pageCount.value });
    return items;
});

function setPage(target) {
    const page = Math.min(Math.max(1, Math.round(Number(target)) || 1), pageCount.value);
    inputPage.value = page;
    if (page !== props.page) emit("change", page);
}
</script>

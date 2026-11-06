<template>
    <div class="detector-meter">
        <div class="d-flex justify-content-between align-items-baseline gap-2">
            <span class="small">{{ label }}</span>
            <span class="fw-semibold">{{ money(spend) }}<span v-if="limit" class="fw-normal text-muted"> of {{ money(limit) }}</span></span>
        </div>
        <div v-if="limit" class="progress mt-1" role="progressbar" :aria-label="label" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" :class="barClass" :style="{ width: percent + '%' }"></div>
        </div>
        <div v-if="note" class="form-text">{{ note }}</div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { money } from "./detector.js";

const props = defineProps({
    label: { type: String, required: true },
    spend: { type: Number, default: 0 },
    limit: { type: Number, default: 0 },
    note: { type: String, default: "" },
});
const percent = computed(() => props.limit ? Math.min(100, Math.round(props.spend / props.limit * 100)) : 0);
const barClass = computed(() => percent.value >= 100 ? "bg-danger" : percent.value >= 80 ? "bg-warning" : "bg-success");
</script>

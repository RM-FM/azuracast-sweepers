<template>
    <svg class="sweepers-icon" viewBox="0 0 24 24" width="1.2em" height="1.2em" aria-hidden="true" focusable="false">
        <path fill="currentColor" :d="icons[name]" />
    </svg>
</template>

<script setup>
import { icons } from "./icons.js";

defineProps({ name: { type: String, required: true } });
</script>

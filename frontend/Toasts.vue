<template>
    <div class="toast-container position-fixed top-0 end-0 p-3" data-sweepers-toasts>
        <div v-for="toast in toasts" :key="toast.id" class="toast align-items-center toast-notification mb-3 fade show" :class="'text-bg-' + toast.variant" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">{{ toast.message }}</div>
                <button type="button" class="btn-close me-2 m-auto" aria-label="Close" @click="removeToast(toast.id)"></button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { removeToast, toasts } from "./toasts.js";
</script>
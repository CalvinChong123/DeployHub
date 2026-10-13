<script setup lang="ts">
defineProps<{ state: string; label?: string }>();
</script>
<template>
    <span class="status" :data-state="state"
        ><span class="status-dot" aria-hidden="true"></span
        >{{ label || state.replace(/_/g, " ") }}</span
    >
</template>

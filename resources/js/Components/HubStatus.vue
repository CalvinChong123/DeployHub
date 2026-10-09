<script setup lang="ts">
defineProps<{ state: string }>();
</script>
<template>
    <span class="status" :data-state="state"
        ><span class="status-dot" aria-hidden="true"></span
        >{{ state.replace(/_/g, " ") }}</span
    >
</template>

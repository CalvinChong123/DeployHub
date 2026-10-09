<script setup lang="ts">
defineProps<{ errors: Record<string, string> }>();
</script>
<template>
    <div v-if="Object.keys(errors).length" class="notice error" role="alert">
        <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
    </div>
</template>

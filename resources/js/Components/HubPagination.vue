<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import type { Paginated } from "@/hub-types";
defineProps<{ pagination: Paginated<unknown> }>();
const label = (value: string) =>
    value.replace(/&laquo;/g, "«").replace(/&raquo;/g, "»");
</script>
<template>
    <nav
        v-if="pagination.total"
        class="card-content stack"
        aria-label="Deployment pagination"
    >
        <span class="muted"
            >{{ pagination.from }}–{{ pagination.to }} of
            {{ pagination.total }}</span
        >
        <template v-for="(link, i) in pagination.links" :key="i">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-state
                preserve-scroll
                :class="['btn', 'small', { primary: link.active }]"
                :aria-current="link.active ? 'page' : undefined"
                >{{ label(link.label) }}</Link
            >
            <span v-else class="btn small muted">{{ label(link.label) }}</span>
        </template>
    </nav>
</template>

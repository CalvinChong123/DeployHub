<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import { date } from "@/hub-types";
defineProps<{
    entries: {
        data: {
            id: number;
            action: string;
            subject: string;
            user?: { name: string };
            metadata: Record<string, unknown>;
            created_at: string;
        }[];
        links: { url?: string; label: string; active: boolean }[];
    };
}>();
</script>
<template>
    <Head title="Audit log" /><HubLayout
        title="Audit log"
        subtitle="An append-only application record of access, configuration and deployment actions."
        ><section class="card">
            <div class="card-heading">
                <h2>Workspace audit trail</h2>
                <span class="badge">Admin only</span>
            </div>
            <div class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Subject / metadata</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries.data" :key="entry.id">
                            <td class="nowrap">{{ date(entry.created_at) }}</td>
                            <td>{{ entry.user?.name || "System" }}</td>
                            <td>
                                <code>{{ entry.action }}</code>
                            </td>
                            <td>
                                <code>{{ entry.subject }}</code>
                                <p v-if="Object.keys(entry.metadata).length">
                                    <code>{{
                                        JSON.stringify(entry.metadata)
                                    }}</code>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-content stack">
                <template v-for="(link, i) in entries.links" :key="i"
                    ><Link
                        v-if="link.url"
                        :href="link.url"
                        :class="['btn', 'small', { primary: link.active }]"
                        >{{
                            link.label
                                .replace(/&laquo;/g, "«")
                                .replace(/&raquo;/g, "»")
                        }}</Link
                    ><span v-else class="btn small muted">{{
                        link.label
                            .replace(/&laquo;/g, "«")
                            .replace(/&raquo;/g, "»")
                    }}</span></template
                >
            </div>
        </section></HubLayout
    >
</template>

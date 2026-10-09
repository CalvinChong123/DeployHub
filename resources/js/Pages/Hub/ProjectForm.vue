<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import EnvironmentFields from "@/Components/EnvironmentFields.vue";
import HubErrors from "@/Components/HubErrors.vue";
import type { Server } from "@/hub-types";
defineProps<{ servers: Server[] }>();
const form = useForm({
    name: "",
    repository: "",
    description: "",
    environment_name: "Staging",
    server_id: "",
    path: "",
    branch: "staging",
    production: false,
    requires_approval: false,
    pipeline: ["cache_clear"] as string[],
    health_url: "",
});
</script>
<template>
    <Head title="Add project" /><HubLayout
        title="Add project"
        subtitle="Map an existing Git repository to a verified SSH target."
        ><section class="card">
            <div class="card-heading"><h2>Project & first environment</h2></div>
            <form class="card-content" @submit.prevent="form.post('/projects')">
                <HubErrors :errors="form.errors" />
                <div class="form-grid" style="margin-bottom: 24px">
                    <div class="field">
                        <label for="name">Project name</label
                        ><input
                            id="name"
                            v-model="form.name"
                            required
                            placeholder="My Laravel app"
                        />
                    </div>
                    <div class="field">
                        <label for="repository">Exact origin URL</label
                        ><input
                            id="repository"
                            v-model="form.repository"
                            required
                            placeholder="git@github.com:team/project.git"
                        /><small
                            >Must match the target's origin URL exactly. Do not
                            enter repository tokens.</small
                        >
                    </div>
                    <div class="field full">
                        <label for="description">Description</label
                        ><textarea
                            id="description"
                            v-model="form.description"
                            rows="2"
                        />
                    </div>
                </div>
                <EnvironmentFields :form="form" :servers="servers" />
                <div class="form-actions">
                    <Link href="/projects" class="btn">Cancel</Link
                    ><button
                        class="btn primary"
                        :disabled="form.processing || !servers.length"
                    >
                        Register project
                    </button>
                </div>
                <p v-if="!servers.length" class="muted">
                    Register a server before creating a project.
                </p>
            </form>
        </section></HubLayout
    >
</template>

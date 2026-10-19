<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import EnvironmentFields from "@/Components/EnvironmentFields.vue";
import HubErrors from "@/Components/HubErrors.vue";
import type { Server, DeploymentHook } from "@/hub-types";
type ProjectInput = {
    copy_of?: number;
    name: string;
    repository: string;
    description: string;
    environment_name: string;
    server_id: string | number;
    path: string;
    branch: string;
    production: boolean;
    pipeline: string[];
    hooks: DeploymentHook[];
    health_url: string;
};
const props = defineProps<{
    servers: Server[];
    initial?: ProjectInput | null;
    defaultHooks?: DeploymentHook[];
}>();
const form = useForm({
    name: "",
    repository: "",
    description: "",
    environment_name: "Staging",
    server_id: "",
    path: "",
    branch: "staging",
    production: false,
    pipeline: [] as string[],
    hooks: props.defaultHooks || ([] as DeploymentHook[]),
    health_url: "",
    ...props.initial,
});
</script>
<template>
    <Head :title="initial ? 'Copy project' : 'Add project'" /><HubLayout
        :title="initial ? 'Copy project' : 'Add project'"
        :subtitle="
            initial
                ? 'Adjust the copied configuration and save an independent deployment project.'
                : 'Map an existing Git repository to a verified SSH target.'
        "
        ><section class="card">
            <div class="card-heading"><h2>Project & deployment target</h2></div>
            <form class="card-content" @submit.prevent="form.post('/projects')">
                <HubErrors :errors="form.errors" />
                <p v-if="initial" class="notice">
                    Repository, branch, server and workflow are copied. Choose a
                    different remote directory or server. The new project has
                    its own access and history; saving does not clone files on
                    the server.
                </p>
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
                        {{
                            initial
                                ? "Create copied project"
                                : "Register project"
                        }}
                    </button>
                </div>
                <p v-if="!servers.length" class="muted">
                    Register a server before creating a project.
                </p>
            </form>
        </section></HubLayout
    >
</template>

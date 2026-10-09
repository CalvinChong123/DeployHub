<script setup lang="ts">
import { stepLabel, type Server } from "@/hub-types";
defineProps<{ form: any; servers: Partial<Server>[] }>();
</script>
<template>
    <div class="form-grid">
        <div class="field">
            <label for="environment_name">Environment name</label
            ><input
                id="environment_name"
                v-model="form.environment_name"
                required
                placeholder="Staging"
            />
        </div>
        <div class="field">
            <label for="server_id">SSH server</label
            ><select id="server_id" v-model="form.server_id" required>
                <option value="">Select a server</option>
                <option v-for="s in servers" :key="s.id" :value="s.id">
                    {{ s.name }}
                </option>
            </select>
        </div>
        <div class="field">
            <label for="path">Absolute remote repository directory</label
            ><input
                id="path"
                v-model="form.path"
                required
                placeholder="/home/deployer/my-app"
                class="mono"
            /><small
                >Must be the Git repository root. No shell commands or
                traversal.</small
            >
        </div>
        <div class="field">
            <label for="branch">Fixed Git branch</label
            ><input
                id="branch"
                v-model="form.branch"
                required
                placeholder="staging"
                class="mono"
            />
        </div>
        <div class="field full">
            <label class="check"
                ><input v-model="form.production" type="checkbox" />This is a
                production environment</label
            ><label class="check"
                ><input
                    v-model="form.requires_approval"
                    type="checkbox"
                    :disabled="form.production"
                />Require independent approval
                <small>{{
                    form.production ? "(always required in production)" : ""
                }}</small></label
            >
        </div>
        <div class="field full">
            <label>Approved pipeline</label
            ><small
                >Repository preflight, fast-forward and final commit
                verification always run. All selected steps are mandatory, in
                the order listed.</small
            >
            <div
                v-for="s in [
                    'composer',
                    'cache_clear',
                    'optimize',
                    'queue_restart',
                    'health',
                ]"
                :key="s"
                class="pipeline-option"
            >
                <label class="check"
                    ><input
                        v-model="form.pipeline"
                        :value="s"
                        type="checkbox"
                    />{{ stepLabel[s]
                    }}<small v-if="s === 'composer'"
                        >scripts and plugins disabled</small
                    ></label
                >
            </div>
        </div>
        <div v-if="form.pipeline.includes('health')" class="field full">
            <label for="health_url">HTTPS health check URL</label
            ><input
                id="health_url"
                v-model="form.health_url"
                type="url"
                required
                placeholder="https://staging.example.com/up"
            /><small
                >Requires a successful HTTP response without redirects. No
                credentials or query strings.</small
            >
        </div>
    </div>
</template>

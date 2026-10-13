<script setup lang="ts">
import { computed, ref } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubErrors from "@/Components/HubErrors.vue";
import type { AutomationTarget, AutomationGrantSummary } from "@/hub-types";

const props = defineProps<{
    targets: AutomationTarget[];
    grants: AutomationGrantSummary[];
    mfaEnabled: boolean;
    issuedToken: string | null;
    mcpUrl: string;
}>();
const form = useForm({
    name: "Codex deployments",
    environment_id: props.targets[0]?.id ?? "",
    days: 14,
    password: "",
});
const revoking = ref<number | null>(null);
const copyMessage = ref("");
const connection = computed(
    () => `[mcp_servers.deployhub]
url = ${JSON.stringify(props.mcpUrl)}
bearer_token_env_var = "DEPLOYHUB_AI_TOKEN"
enabled_tools = ["list_deployment_targets", "prepare_deployment", "execute_deployment", "get_deployment_status"]
default_tools_approval_mode = "approve"
tool_timeout_sec = 180`,
);

function revoke(id: number) {
    revoking.value = id;
    router.delete(`/automation/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            revoking.value = null;
        },
    });
}

async function copyToken() {
    try {
        await navigator.clipboard.writeText(props.issuedToken ?? "");
        copyMessage.value = "Token copied.";
    } catch {
        copyMessage.value = "Select the token and copy it manually.";
    }
}
</script>

<template>
    <Head title="AI deployment access" />
    <HubLayout
        title="AI deployment access"
        subtitle="Let Codex deploy an authorized deployment target when you ask."
    >
        <template #actions
            ><Link href="/security" class="btn"
                >Account security</Link
            ></template
        >
        <section v-if="issuedToken" class="card" style="margin-bottom: 24px">
            <div class="card-heading"><h2>Copy your new token</h2></div>
            <div class="card-content">
                <p class="muted">
                    This token will not be shown again. Store it securely as
                    DEPLOYHUB_AI_TOKEN on the computer running Codex.
                </p>
                <div class="field">
                    <label for="issued-token">Automation token</label
                    ><input
                        id="issued-token"
                        :value="issuedToken"
                        readonly
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
                <div class="form-actions">
                    <button
                        class="btn primary"
                        type="button"
                        @click="copyToken"
                    >
                        Copy token</button
                    ><Link href="/automation" class="btn">Hide token</Link
                    ><span role="status">{{ copyMessage }}</span>
                </div>
            </div>
        </section>
        <div class="section-grid">
            <section class="card">
                <div class="card-heading">
                    <h2>Authorize a deployment target</h2>
                </div>
                <div class="card-content">
                    <p class="muted">
                        Each token can prepare, execute and read its own
                        deployments for one target. Unprotected targets can run
                        without a website confirmation. Production and protected
                        targets retain their independent approval requirement.
                    </p>
                    <div v-if="!mfaEnabled" class="notice warning">
                        Enable your authenticator in
                        <Link href="/security">Account security</Link> before
                        creating a token.
                    </div>
                    <div v-else-if="!targets.length" class="notice warning">
                        No eligible targets. You need explicit deployment
                        permission for a registered deployment target.
                    </div>
                    <form
                        v-else
                        @submit.prevent="
                            form.post('/automation', {
                                preserveScroll: true,
                                onFinish: () => form.reset('password'),
                            })
                        "
                    >
                        <HubErrors :errors="form.errors" />
                        <div class="field">
                            <label for="grant-name">Token name</label
                            ><input
                                id="grant-name"
                                v-model="form.name"
                                maxlength="100"
                                required
                            />
                        </div>
                        <div class="field">
                            <label for="grant-target">Deployment target</label
                            ><select
                                id="grant-target"
                                v-model="form.environment_id"
                                required
                            >
                                <option
                                    v-for="target in targets"
                                    :key="target.id"
                                    :value="target.id"
                                >
                                    {{ target.project }} / {{ target.name }} ·
                                    {{ target.branch }}
                                    {{
                                        target.requires_approval
                                            ? "· Independent approval"
                                            : ""
                                    }}
                                </option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="grant-days">Valid for (days)</label
                            ><input
                                id="grant-days"
                                v-model="form.days"
                                type="number"
                                min="1"
                                max="90"
                                required
                            />
                        </div>
                        <div class="field">
                            <label for="grant-password"
                                >Confirm your password</label
                            ><input
                                id="grant-password"
                                v-model="form.password"
                                type="password"
                                required
                                autocomplete="current-password"
                            />
                        </div>
                        <div class="form-actions">
                            <button
                                class="btn primary"
                                :disabled="form.processing"
                            >
                                Create deployment token
                            </button>
                        </div>
                    </form>
                </div>
            </section>
            <section class="card">
                <div class="card-heading"><h2>Connect Codex</h2></div>
                <div class="card-content">
                    <p class="muted">
                        Add this server to your Codex MCP configuration.
                        Configure the token in the host environment, then
                        restart Codex to load it. Keep the DeployHub application
                        and worker running.
                    </p>
                    <pre class="log connection-config">{{ connection }}</pre>
                    <p class="muted">
                        After connecting, ask Codex to list the target, push
                        your changes, and deploy the exact commit to the
                        authorized target. Deployment logs remain available in
                        DeployHub.
                    </p>
                </div>
            </section>
        </div>
        <section class="card" style="margin-top: 24px">
            <div class="card-heading">
                <h2>Your automation tokens</h2>
                <span class="badge">Latest 50</span>
            </div>
            <div class="card-content">
                <p class="muted">
                    Changing your password, resetting MFA, losing deployment
                    access or changing the target repository, branch or server
                    invalidates the token. Revocation stops further steps after
                    any current command finishes.
                </p>
                <p v-if="!grants.length" class="muted">
                    No automation tokens created.
                </p>
                <div v-else class="table-wrap">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th>Name / target</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="grant in grants" :key="grant.id">
                                <td>
                                    {{ grant.name }}
                                    <div class="muted">{{ grant.target }}</div>
                                </td>
                                <td>
                                    {{
                                        new Date(
                                            grant.expires_at,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td>
                                    {{
                                        grant.revoked_at
                                            ? "Revoked"
                                            : grant.expired
                                              ? "Expired"
                                              : "Issued"
                                    }}
                                </td>
                                <td>
                                    <button
                                        v-if="
                                            !grant.revoked_at && !grant.expired
                                        "
                                        class="btn"
                                        type="button"
                                        :disabled="revoking !== null"
                                        @click="revoke(grant.id)"
                                    >
                                        {{
                                            revoking === grant.id
                                                ? "Revoking…"
                                                : "Revoke"
                                        }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </HubLayout>
</template>

<style scoped>
.connection-config {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>

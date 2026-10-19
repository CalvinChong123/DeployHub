<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
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
    environment_ids: [] as number[],
    days: 14,
    password: "",
});
const revoking = ref<number | null>(null);
const copyMessage = ref("");
const issuedTokenCard = ref<HTMLElement | null>(null);
const issuedTokenInput = ref<HTMLInputElement | null>(null);

watch(
    () => props.issuedToken,
    (token) => {
        copyMessage.value = "";
        if (!token) return;
        nextTick(() => {
            issuedTokenCard.value?.scrollIntoView({ block: "start" });
            issuedTokenInput.value?.focus({ preventScroll: true });
        });
    },
    { immediate: true },
);
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
        subtitle="Let Codex deploy the targets you explicitly authorize when you ask."
    >
        <template #actions
            ><Link href="/security" class="btn"
                >Account security</Link
            ></template
        >
        <section
            v-if="issuedToken"
            ref="issuedTokenCard"
            class="card"
            style="margin-bottom: 24px"
            aria-labelledby="new-token-heading"
        >
            <div class="card-heading">
                <h2 id="new-token-heading">Copy your new token</h2>
            </div>
            <div class="card-content">
                <p class="muted">
                    Copy this token now; it will not be shown again after you
                    leave or refresh this page. DeployHub does not save it to
                    your computer or configure Codex automatically. Store it
                    securely as DEPLOYHUB_AI_TOKEN on the computer running
                    Codex.
                </p>
                <div class="field">
                    <label for="issued-token">Automation token</label
                    ><input
                        id="issued-token"
                        ref="issuedTokenInput"
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
                    <h2>Authorize deployment targets</h2>
                </div>
                <div class="card-content">
                    <p class="muted">
                        Each token can prepare, execute and read its own
                        deployments for the targets you select in this
                        workspace, including production. Execute confirms the
                        reviewed candidate without a separate website approval.
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
                                preserveScroll: 'errors',
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
                        <fieldset class="field target-selection">
                            <legend>Deployment targets</legend>
                            <p id="grant-target-help" class="muted">
                                Select 1–100 targets. New projects are not added
                                automatically. Create a new token to change this
                                selection.
                            </p>
                            <div class="target-options">
                                <label
                                    v-for="target in targets"
                                    :key="target.id"
                                    class="check target-option"
                                >
                                    <input
                                        v-model="form.environment_ids"
                                        type="checkbox"
                                        :value="target.id"
                                        :disabled="form.processing"
                                        aria-describedby="grant-target-help"
                                    />
                                    <span>
                                        {{ target.project }} /
                                        {{ target.name }} ·
                                        {{ target.branch }}
                                    </span>
                                </label>
                            </div>
                            <span class="muted" role="status">
                                {{ form.environment_ids.length }} selected
                            </span>
                        </fieldset>
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
                                :disabled="
                                    form.processing ||
                                    !form.environment_ids.length ||
                                    form.environment_ids.length > 100
                                "
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
                        After connecting, ask Codex to list the targets, select
                        the intended project, push your changes, and deploy the
                        exact commit to the authorized target. Deployment logs
                        remain available in DeployHub.
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
                    Changing your password, resetting MFA or revoking the token
                    stops access to all selected targets. Losing deployment
                    access or changing a target's repository, branch or server
                    invalidates that target's authorization. Other valid targets
                    remain usable. Revocation stops further steps after any
                    current command finishes.
                </p>
                <p v-if="!grants.length" class="muted">
                    No automation tokens created.
                </p>
                <div v-else class="table-wrap">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th>Name / targets</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="grant in grants" :key="grant.id">
                                <td>
                                    {{ grant.name }}
                                    <ul class="muted token-targets">
                                        <li
                                            v-for="target in grant.targets"
                                            :key="target.id"
                                        >
                                            {{ target.label }}
                                        </li>
                                    </ul>
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
.target-selection {
    min-width: 0;
    border: 0;
    padding: 0;
}
.target-options {
    display: grid;
    gap: 10px;
    max-height: 280px;
    overflow-y: auto;
    margin: 12px 0;
}
.target-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    overflow-wrap: anywhere;
}
.target-option input {
    flex-shrink: 0;
    margin-top: 3px;
}
.token-targets {
    margin: 6px 0 0;
    padding-left: 18px;
    overflow-wrap: anywhere;
}
</style>

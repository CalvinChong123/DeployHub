<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubStatus from "@/Components/HubStatus.vue";
import HubErrors from "@/Components/HubErrors.vue";
import EnvironmentFields from "@/Components/EnvironmentFields.vue";
import HubPagination from "@/Components/HubPagination.vue";
import {
    date,
    short,
    type Environment,
    type Deployment,
    type Member,
    type Permission,
    type Server,
    type Paginated,
    inspectionLabel,
} from "@/hub-types";
const props = defineProps<{
    environment: Environment;
    canDeploy: boolean;
    canManage: boolean;
    members: Member[];
    permissions: Permission[];
    deployments: Paginated<Deployment>;
    servers: Server[];
    serverAccess: Record<number, number[]>;
}>();
const tab = ref("overview");
const preflight = useForm({});
const candidate = useForm({ idempotency_key: crypto.randomUUID() });
const env = props.environment;
const projectForm = useForm({
    name: env.project?.name || "",
    repository: env.project?.repository || "",
    description: env.project?.description || "",
});
function removeProject() {
    if (
        window.confirm(
            `Delete ${env.project?.name} (${env.name}) from DeployHub? Deployment history is retained. Other projects and remote files are unaffected.`,
        )
    )
        projectForm.delete(`/projects/${env.project_id}`);
}
const configuration = useForm({
    environment_name: env.name,
    server_id: env.server_id,
    path: env.path,
    branch: env.branch,
    production: env.production,
    requires_approval: env.requires_approval,
    pipeline: [] as string[],
    hooks: (env.hooks || []).map((hook) => ({ ...hook })),
    health_url: env.health_url || "",
    transfer_server_access: false,
});
const affectedMembers = computed(() => {
    if (Number(configuration.server_id) === props.environment.server_id)
        return [];
    const existing = props.serverAccess[Number(configuration.server_id)] || [];
    return props.members.filter(
        (member) =>
            props.permissions.some(
                (permission) =>
                    permission.user_id === member.id && permission.can_view,
            ) && !existing.includes(member.id),
    );
});
watch(
    () => configuration.server_id,
    () => {
        configuration.transfer_server_access = false;
    },
);
const grant = useForm({
    user_id: "",
    can_view: true,
    can_deploy: false,
    can_approve: false,
});
function selectMember() {
    const permission = props.permissions.find(
        (p) => p.user_id === Number(grant.user_id),
    );
    grant.can_view = permission?.can_view ?? true;
    grant.can_deploy = permission?.can_deploy ?? false;
    grant.can_approve = permission?.can_approve ?? false;
}
</script>
<template>
    <Head
        :title="`${environment.project?.name} / ${environment.name}`"
    /><HubLayout
        :title="environment.project?.name || 'Project'"
        :subtitle="`${environment.name} · ${environment.server.name}`"
        ><template #actions
            ><button
                class="btn"
                :disabled="preflight.processing"
                @click="
                    preflight.post(`/environments/${environment.id}/preflight`)
                "
            >
                {{
                    preflight.processing
                        ? "Checking repository…"
                        : "Check repository"
                }}</button
            ><button
                v-if="canDeploy"
                class="btn primary"
                :disabled="candidate.processing"
                @click="
                    candidate.post(
                        `/environments/${environment.id}/deployment-requests`,
                    )
                "
            >
                {{
                    candidate.processing ? "Preparing…" : "Prepare deployment →"
                }}
            </button></template
        ><HubErrors :errors="candidate.errors" />
        <section class="card project-summary">
            <div class="summary-grid">
                <div>
                    <span class="meta-label">Branch & target</span>
                    <strong>{{ environment.branch }}</strong>
                    <p>
                        {{ environment.name }} · {{ environment.server.name }}
                    </p>
                    <small v-if="environment.production" class="muted"
                        >Production · independent approval</small
                    >
                </div>
                <div>
                    <span class="meta-label">Last successful deployment</span>
                    <template v-if="environment.last_successful_deployment">
                        <Link
                            :href="`/deployments/${environment.last_successful_deployment.id}`"
                            class="link"
                            ><code>{{
                                short(
                                    environment.last_successful_deployment
                                        .target_sha,
                                )
                            }}</code>
                            →</Link
                        >
                        <p>
                            {{
                                date(
                                    environment.last_successful_deployment
                                        .finished_at,
                                )
                            }}
                        </p>
                        <small class="muted">{{
                            environment.last_successful_deployment.user?.name
                        }}</small>
                    </template>
                    <p v-else class="muted">No successful deployment yet</p>
                </div>
                <div>
                    <span class="meta-label">Incoming · last check</span>
                    <strong v-if="environment.last_preflight?.count != null"
                        >{{ environment.last_preflight.count }} commit{{
                            environment.last_preflight.count === 1 ? "" : "s"
                        }}</strong
                    >
                    <strong v-else>Not checked</strong>
                    <p class="muted">{{ date(environment.checked_at) }}</p>
                </div>
            </div>
            <div v-if="environment.latest_deployment" class="latest-result">
                <div class="deployment-result">
                    <span class="muted">Latest deployment</span
                    ><HubStatus
                        :state="environment.latest_deployment.state"
                    /><code>{{
                        short(environment.latest_deployment.target_sha)
                    }}</code>
                </div>
                <Link
                    :href="`/deployments/${environment.latest_deployment.id}`"
                    class="link"
                    >View deployment →</Link
                >
            </div>
            <p
                v-if="environment.latest_deployment?.scheduled_for"
                class="summary-note muted"
            >
                Scheduled for
                {{ date(environment.latest_deployment.scheduled_for) }}
            </p>
        </section>
        <div
            v-if="
                environment.latest_deployment?.state === 'failed' &&
                environment.latest_deployment.code_updated
            "
            class="notice warning"
        >
            The latest deployment changed code before failing. Review its
            completed steps and check the application before deploying again.
        </div>
        <details class="card repository-details">
            <summary>
                Repository details
                <HubStatus
                    :state="environment.status"
                    :label="inspectionLabel(environment.status)"
                />
            </summary>
            <div class="meta-grid">
                <div>
                    <span class="meta-label">Environment</span
                    ><span class="meta-value"
                        >{{ environment.name }}
                        <span v-if="environment.production" class="badge"
                            >Production</span
                        ></span
                    >
                </div>
                <div>
                    <span class="meta-label">Configured branch</span
                    ><code>{{ environment.branch }}</code>
                </div>
                <div>
                    <span class="meta-label">Repository check</span
                    ><HubStatus
                        :state="environment.status"
                        :label="inspectionLabel(environment.status)"
                    />
                </div>
                <div>
                    <span class="meta-label">Remote directory</span
                    ><code>{{ environment.path }}</code>
                </div>
                <div>
                    <span class="meta-label"
                        >Last inspected HEAD / fetched origin</span
                    ><code
                        >{{ short(environment.last_preflight?.head) }} →
                        {{ short(environment.last_preflight?.target) }}</code
                    >
                </div>
                <div>
                    <span class="meta-label">Last inspection</span
                    ><span class="meta-value">{{
                        date(environment.checked_at)
                    }}</span>
                </div>
            </div>
            <div class="card-content detail-actions" v-if="canManage">
                <Link :href="`/environments/${environment.id}/copy`" class="btn"
                    >Copy project</Link
                >
            </div>
        </details>
        <div class="tabs">
            <button
                v-for="name in [
                    'overview',
                    'deployments',
                    ...(canManage
                        ? ['pipeline & settings', 'access', 'project settings']
                        : []),
                ]"
                :key="name"
                :class="['tab', { active: tab === name }]"
                @click="tab = name"
            >
                {{ name.charAt(0).toUpperCase() + name.slice(1) }}
            </button>
        </div>
        <template v-if="tab === 'overview'"
            ><div
                v-if="environment.last_preflight?.error"
                class="notice error"
                role="alert"
            >
                {{ environment.last_preflight.error }}
            </div>
            <details
                v-else-if="environment.last_preflight?.head"
                class="inspection-note"
            >
                <summary>
                    {{
                        environment.last_preflight.clean === false
                            ? "Local working tree changes"
                            : "Repository check passed"
                    }}
                    · Matching branch · Fast-forward history · Fetch completed
                    {{ date(environment.last_preflight.fetched_at) }}
                    <small
                        v-if="environment.last_preflight.duration_ms != null"
                    >
                        ·
                        {{
                            (
                                environment.last_preflight.duration_ms / 1000
                            ).toFixed(1)
                        }}s
                    </small>
                </summary>
                <p class="muted">
                    Review reuses a successful inspection for up to two minutes
                    while configuration is unchanged. Deployment rechecks the
                    repository before running any steps.
                </p>
                <template v-if="environment.last_preflight.clean === false"
                    ><p>
                        These changes do not block review. Git keeps unrelated
                        local changes and stops if the update would overwrite
                        them. Enable the Pre deploy Hard reset option if you
                        want tracked changes discarded.
                    </p>
                    <pre class="mono">{{
                        environment.last_preflight.changes
                    }}</pre>
                </template>
            </details>
            <div v-else class="notice warning">
                No repository check yet. Prepare deployment will check the
                repository before you confirm.
            </div>
            <section class="card">
                <div class="card-heading">
                    <div>
                        <h2>
                            Incoming commits
                            <span class="count-badge">{{
                                environment.last_preflight?.count ?? "—"
                            }}</span>
                        </h2>
                        <p>
                            Origin is shown only after a successful fetch.
                            Confirmation freezes the exact target commit.
                        </p>
                    </div>
                </div>
                <div
                    v-if="!environment.last_preflight?.commits?.length"
                    class="empty"
                >
                    <h2>
                        {{
                            environment.last_preflight?.count === 0
                                ? "Already up to date"
                                : "No commit preview available"
                        }}
                    </h2>
                    <p>
                        {{
                            environment.last_preflight?.count === 0
                                ? "The target is at the fetched origin commit."
                                : "Run preflight to check the repository and fetch the configured branch."
                        }}
                    </p>
                </div>
                <div
                    v-for="commit in environment.last_preflight?.commits"
                    :key="commit.sha"
                    class="commit"
                >
                    <code>{{ short(commit.sha) }}</code>
                    <div>
                        <strong>{{ commit.subject }}</strong>
                        <p>
                            <small
                                >{{ commit.author }} ·
                                {{ date(commit.date) }}</small
                            >
                        </p>
                    </div>
                </div>
            </section></template
        >
        <section v-if="tab === 'deployments'" class="card">
            <div class="card-heading"><h2>Deployment history</h2></div>
            <div v-if="!deployments.data.length" class="empty">
                <h2>No deployments yet</h2>
                <p>
                    Review a deployment to create your first immutable
                    candidate.
                </p>
            </div>
            <div v-else class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>Deployment</th>
                            <th>Commit</th>
                            <th>Requester</th>
                            <th>State</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in deployments.data" :key="d.id">
                            <td>
                                <Link
                                    :href="`/deployments/${d.id}`"
                                    class="link"
                                    >DEP-{{
                                        String(d.id).padStart(6, "0")
                                    }}</Link
                                >
                            </td>
                            <td>
                                <code>{{ short(d.target_sha) }}</code>
                            </td>
                            <td>{{ d.user?.name }}</td>
                            <td>
                                <HubStatus :state="d.state" />
                                <p v-if="d.scheduled_for" class="muted">
                                    Scheduled: {{ date(d.scheduled_for) }}
                                </p>
                            </td>
                            <td>{{ date(d.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <HubPagination :pagination="deployments" />
        </section>
        <section v-if="tab === 'pipeline & settings'" class="card">
            <div class="card-heading">
                <h2>
                    Approved pipeline · version
                    {{ environment.pipeline_version }}
                </h2>
            </div>
            <form
                class="card-content"
                @submit.prevent="
                    configuration.put(`/environments/${environment.id}`)
                "
            >
                <HubErrors :errors="configuration.errors" /><EnvironmentFields
                    :form="configuration"
                    :servers="servers"
                />
                <div v-if="affectedMembers.length" class="notice warning">
                    <p>
                        Changing the server requires access on the selected
                        server for:
                        {{
                            affectedMembers
                                .map((member) => member.name)
                                .join(", ")
                        }}. Their environment permissions will be preserved.
                    </p>
                    <label class="check"
                        ><input
                            v-model="configuration.transfer_server_access"
                            type="checkbox"
                            required
                        />Grant these existing environment members access on the
                        selected server.</label
                    >
                </div>
                <div class="form-actions">
                    <button
                        class="btn primary"
                        :disabled="configuration.processing"
                    >
                        Save new version
                    </button>
                </div>
            </form>
        </section>
        <section v-if="tab === 'access'" class="card">
            <div class="card-heading">
                <div>
                    <h2>Environment access</h2>
                    <p>
                        Explicit grants cover this environment, project and
                        server. Viewers cannot deploy or approve.
                    </p>
                </div>
            </div>
            <div class="card-content">
                <div class="table-wrap">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>View</th>
                                <th>Deploy</th>
                                <th>Approve</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="member in members" :key="member.id">
                                <td>
                                    {{ member.name }}
                                    <p>{{ member.pivot.role }}</p>
                                </td>
                                <td>
                                    {{
                                        permissions.find(
                                            (p) => p.user_id === member.id,
                                        )?.can_view
                                            ? "Yes"
                                            : "No"
                                    }}
                                </td>
                                <td>
                                    {{
                                        permissions.find(
                                            (p) => p.user_id === member.id,
                                        )?.can_deploy
                                            ? "Yes"
                                            : "No"
                                    }}
                                </td>
                                <td>
                                    {{
                                        permissions.find(
                                            (p) => p.user_id === member.id,
                                        )?.can_approve
                                            ? "Yes"
                                            : "No"
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <form
                    style="margin-top: 24px"
                    @submit.prevent="
                        grant.post(
                            `/environments/${environment.id}/permissions`,
                        )
                    "
                >
                    <HubErrors :errors="grant.errors" />
                    <div class="field">
                        <label for="member">Edit member access</label
                        ><select
                            id="member"
                            v-model="grant.user_id"
                            required
                            @change="selectMember"
                        >
                            <option value="">Choose a member</option>
                            <option
                                v-for="m in members"
                                :key="m.id"
                                :value="m.id"
                            >
                                {{ m.name }} ({{ m.pivot.role }})
                            </option>
                        </select>
                    </div>
                    <div class="stack">
                        <label class="check"
                            ><input
                                v-model="grant.can_view"
                                type="checkbox"
                            />View</label
                        ><label class="check"
                            ><input
                                v-model="grant.can_deploy"
                                type="checkbox"
                            />Deploy</label
                        ><label class="check"
                            ><input
                                v-model="grant.can_approve"
                                type="checkbox"
                            />Approve</label
                        >
                    </div>
                    <div class="form-actions">
                        <button
                            class="btn primary"
                            :disabled="grant.processing"
                        >
                            Save access
                        </button>
                    </div>
                </form>
            </div>
        </section>
        <section v-if="tab === 'project settings'" class="card">
            <div class="card-heading"><h2>Project registry</h2></div>
            <form
                class="card-content"
                @submit.prevent="
                    projectForm.put(`/projects/${environment.project_id}`)
                "
            >
                <HubErrors :errors="projectForm.errors" />
                <div class="form-grid">
                    <div class="field">
                        <label for="project_name">Project name</label
                        ><input
                            id="project_name"
                            v-model="projectForm.name"
                            required
                        />
                    </div>
                    <div class="field">
                        <label for="repository">Exact origin URL</label
                        ><input
                            id="repository"
                            v-model="projectForm.repository"
                            required
                        />
                    </div>
                    <div class="field full">
                        <label for="description">Description</label
                        ><textarea
                            id="description"
                            v-model="projectForm.description"
                            rows="2"
                        />
                    </div>
                </div>
                <div class="form-actions">
                    <button
                        type="button"
                        class="btn danger"
                        :disabled="projectForm.processing"
                        @click="removeProject"
                    >
                        Delete project</button
                    ><button
                        class="btn primary"
                        :disabled="projectForm.processing"
                    >
                        Save project
                    </button>
                </div>
            </form>
        </section>
    </HubLayout>
</template>

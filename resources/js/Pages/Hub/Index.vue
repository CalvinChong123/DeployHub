<script setup lang="ts">
import { computed, ref, watch, onUnmounted } from "vue";
import { Head, Link, usePage, router } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubStatus from "@/Components/HubStatus.vue";
import HubPagination from "@/Components/HubPagination.vue";
import {
    date,
    short,
    type Project,
    type Deployment,
    type Paginated,
} from "@/hub-types";
const props = defineProps<{
    section: string;
    projects: Project[];
    deployments: Paginated<Deployment>;
    filters: { q: string; state: string };
    stats: Record<string, number>;
    worker: { online: boolean; seen_at?: string };
}>();
const search = ref(props.filters.q);
const state = ref(props.filters.state);
let filterTimer: ReturnType<typeof setTimeout>;
watch([search, state], () => {
    if (!["activity", "approvals"].includes(props.section)) return;
    clearTimeout(filterTimer);
    filterTimer = setTimeout(
        () =>
            router.get(
                `/${props.section}`,
                { q: search.value, state: state.value },
                { preserveState: true, replace: true },
            ),
        300,
    );
});
onUnmounted(() => clearTimeout(filterTimer));
const page = usePage<any>();
const admin = computed(() => ["owner", "admin"].includes(page.props.auth.role));
const title = computed(
    () =>
        ({
            dashboard: "Overview",
            "projects.index": "Projects",
            activity: "Deployment activity",
            approvals: "Approvals",
        })[props.section] || "Overview",
);
const rows = computed(() =>
    props.projects
        .flatMap((p) => p.environments.map((e) => ({ project: p, env: e })))
        .filter((r) =>
            `${r.project.name} ${r.env.name}`
                .toLowerCase()
                .includes(search.value.toLowerCase()),
        ),
);
const history = computed(() => props.deployments.data);
</script>
<template>
    <Head :title="title" />
    <HubLayout
        :title="title"
        subtitle="A clear view of your projects, environments and every deployment."
    >
        <template #actions
            ><Link v-if="admin" href="/projects/create" class="btn primary"
                ><span>＋</span>Add project</Link
            ></template
        >
        <div v-if="section === 'dashboard'" class="stats-grid">
            <div
                v-for="item in [
                    {
                        label: 'Projects',
                        value: stats.projects,
                        detail: 'Visible in your workspace',
                        icon: '▤',
                    },
                    {
                        label: 'Environments',
                        value: stats.environments,
                        detail: 'Authorized deployment targets',
                        icon: '◈',
                    },
                    {
                        label: 'Deployments',
                        value: stats.deployments,
                        detail: 'In the last 30 days',
                        icon: '↗',
                    },
                    {
                        label: 'Needs approval',
                        value: stats.approvals,
                        detail: 'Independent review required',
                        icon: '✓',
                    },
                ]"
                :key="item.label"
                class="stat-card"
            >
                <div class="stat-label">
                    {{ item.label }}<span>{{ item.icon }}</span>
                </div>
                <div class="stat-value">{{ item.value }}</div>
                <div class="stat-detail">{{ item.detail }}</div>
            </div>
        </div>
        <div
            class="notice"
            :class="worker.online ? 'success' : 'warning'"
            role="status"
        >
            <strong>{{
                worker.online
                    ? "Queue worker online"
                    : "Queue worker offline or unconfirmed"
            }}</strong
            ><span class="muted">
                ·
                {{
                    worker.online
                        ? "Ready to process confirmed candidates."
                        : "Start the queue worker before executing deployments."
                }}
                Last heartbeat: {{ date(worker.seen_at) }}</span
            >
        </div>
        <section
            v-if="['dashboard', 'projects.index'].includes(section)"
            class="card"
        >
            <div class="card-heading">
                <h2>
                    Your projects
                    <span class="count-badge">{{ stats.projects }}</span>
                </h2>
                <input
                    v-model="search"
                    class="search"
                    type="search"
                    aria-label="Search projects"
                    placeholder="Search projects…"
                />
            </div>
            <div v-if="!rows.length" class="empty">
                <div class="empty-symbol">▤</div>
                <h2>
                    {{
                        projects.length
                            ? "No matching environments"
                            : "Your first deployment starts here"
                    }}
                </h2>
                <p>
                    {{
                        admin
                            ? "Register a verified SSH server, connect a project and review its first deployment."
                            : "Ask a workspace administrator to grant you project and environment access."
                    }}
                </p>
                <Link
                    v-if="admin && !projects.length"
                    href="/servers"
                    class="btn primary"
                    >Register a server →</Link
                >
            </div>
            <div v-else class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Environment</th>
                            <th>Branch / server</th>
                            <th>Readiness</th>
                            <th>Last checked</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.env.id">
                            <td>
                                <Link
                                    :href="`/environments/${row.env.id}`"
                                    class="table-name"
                                    >{{ row.project.name }}</Link
                                >
                                <p>
                                    {{
                                        row.project.description ||
                                        "Git deployment project"
                                    }}
                                </p>
                            </td>
                            <td>
                                <span class="badge">{{ row.env.name }}</span>
                                <p v-if="row.env.production">
                                    Independent approval
                                </p>
                            </td>
                            <td>
                                <code>{{ row.env.branch }}</code>
                                <p>{{ row.env.server.name }}</p>
                            </td>
                            <td><HubStatus :state="row.env.status" /></td>
                            <td class="muted">
                                {{ date(row.env.checked_at) }}
                            </td>
                            <td>
                                <Link
                                    :href="`/environments/${row.env.id}`"
                                    class="link nowrap"
                                    >Review →</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section v-if="section !== 'projects.index'" class="card">
            <div class="card-heading">
                <div>
                    <h2>
                        {{
                            section === "approvals"
                                ? "Production & protected environment approvals"
                                : "Recent deployments"
                        }}
                    </h2>
                    <p>
                        {{
                            section === "approvals"
                                ? "Approval is bound to an exact commit and pipeline. Requesters cannot self-approve."
                                : "Who shipped what, where and how it finished."
                        }}
                    </p>
                </div>
                <div class="stack">
                    <input
                        v-if="section !== 'dashboard'"
                        v-model="search"
                        class="search"
                        placeholder="Search activity…"
                        aria-label="Search deployments"
                    /><select
                        v-if="section === 'activity'"
                        v-model="state"
                        class="search"
                        aria-label="Filter by state"
                    >
                        <option value="all">All states</option>
                        <option
                            v-for="s in [
                                'awaiting_confirmation',
                                'awaiting_approval',
                                'queued',
                                'running',
                                'succeeded',
                                'failed',
                                'unknown',
                                'expired',
                                'rejected',
                                'cancelled',
                                'reconciled',
                            ]"
                            :key="s"
                        >
                            {{ s }}
                        </option>
                    </select>
                </div>
            </div>
            <div v-if="!history.length" class="empty">
                <div class="empty-symbol">
                    {{ section === "approvals" ? "✓" : "↗" }}
                </div>
                <h2>
                    {{
                        section === "approvals"
                            ? "No pending approvals"
                            : search || state !== "all"
                              ? "No matching deployments"
                              : "No deployments yet"
                    }}
                </h2>
                <p>
                    {{
                        section === "approvals"
                            ? "Protected deployment requests will appear here for independent review."
                            : "Every confirmed deployment will leave a trace here, including its steps and sanitized output."
                    }}
                </p>
            </div>
            <div v-else class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>Deployment / project</th>
                            <th>Environment</th>
                            <th>Commit</th>
                            <th>Requested by</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in history" :key="d.id">
                            <td>
                                <Link
                                    :href="`/deployments/${d.id}`"
                                    class="table-name"
                                    >DEP-{{
                                        String(d.id).padStart(6, "0")
                                    }}</Link
                                >
                                <p>{{ d.environment?.project?.name }}</p>
                            </td>
                            <td>{{ d.environment?.name }}</td>
                            <td>
                                <code>{{ short(d.target_sha) }}</code>
                                <p>{{ date(d.created_at) }}</p>
                            </td>
                            <td>{{ d.user?.name }}</td>
                            <td><HubStatus :state="d.state" /></td>
                            <td>
                                <Link
                                    :href="`/deployments/${d.id}`"
                                    class="link"
                                    >Open →</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <HubPagination :pagination="deployments" />
        </section>
    </HubLayout>
</template>

<script setup lang="ts">
import { ref, watch, onUnmounted, computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import axios from "axios";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubStatus from "@/Components/HubStatus.vue";
import HubErrors from "@/Components/HubErrors.vue";
import { date, short, stepLabel, duration, type Deployment } from "@/hub-types";
const props = defineProps<{
    deployment: Deployment;
    canConfirm: boolean;
    canApprove: boolean;
    canRecover: boolean;
    canReconcile: boolean;
    workerOnline: boolean;
    scheduler?: { online: boolean; seen_at?: string | null };
}>();
const current = ref(props.deployment);
const pollError = ref("");
const copying = ref(false);
const copiedCommit = ref(false);
const clock = ref(Date.now());
const tick = setInterval(() => {
    clock.value = Date.now();
}, 1000);
let copyTimer: ReturnType<typeof setTimeout>;
const hooks = computed(() => current.value.candidate.hooks || []);
function label(type: string) {
    const hook = hooks.value.find(
        (item) => `${item.phase}_hook:${item.id}` === type,
    );
    return hook
        ? `${hook.phase === "pre" ? "Pre" : "Post"}: ${hook.name}`
        : stepLabel[type] || type;
}
const plannedTypes = computed(() => [
    "preflight",
    ...hooks.value
        .filter((hook) => hook.phase === "pre")
        .map((hook) => `pre_hook:${hook.id}`),
    ...(hooks.value.some((hook) => hook.phase === "pre")
        ? ["repository_ready"]
        : []),
    ...(current.value.candidate.recovery_of ? [] : ["fast_forward"]),
    ...current.value.candidate.pipeline.filter((type) => type !== "health"),
    ...hooks.value
        .filter((hook) => hook.phase === "post")
        .map((hook) => `post_hook:${hook.id}`),
    ...(current.value.candidate.pipeline.includes("health") ? ["health"] : []),
    "verify_head",
]);
const plannedSteps = computed(() => plannedTypes.value.map(label).join(" → "));
const reviewing = computed(() =>
    ["awaiting_confirmation", "awaiting_approval"].includes(
        current.value.state,
    ),
);
const targetMessage = computed(
    () =>
        current.value.preflight.commits?.find(
            (commit) => commit.sha === current.value.target_sha,
        )?.subject,
);
const activeStep = computed(() =>
    current.value.steps.find((step) => step.state === "running"),
);
const phases = computed(() => {
    const definitions = [
        {
            name: "Repository check",
            matches: (type: string) => type === "preflight",
        },
        {
            name: "Pre deploy",
            matches: (type: string) =>
                type.startsWith("pre_hook:") || type === "repository_ready",
        },
        {
            name: "Git update",
            matches: (type: string) => type === "fast_forward",
        },
        {
            name: "Post deploy",
            matches: (type: string) =>
                type.startsWith("post_hook:") ||
                [
                    "composer",
                    "cache_clear",
                    "optimize",
                    "queue_restart",
                ].includes(type),
        },
        {
            name: "Verification",
            matches: (type: string) => ["health", "verify_head"].includes(type),
        },
    ];
    return definitions
        .map((phase) => {
            const types = plannedTypes.value.filter(phase.matches);
            const steps = current.value.steps.filter((step) =>
                types.includes(step.type),
            );
            const state = steps.some((step) => step.state === "unknown")
                ? "unknown"
                : steps.some((step) => step.state === "failed")
                  ? "failed"
                  : steps.some((step) => step.state === "running")
                    ? "running"
                    : types.length > 0 &&
                        types.every((type) =>
                            steps.some(
                                (step) =>
                                    step.type === type &&
                                    step.state === "succeeded",
                            ),
                        )
                      ? "succeeded"
                      : [
                              "failed",
                              "unknown",
                              "cancelled",
                              "expired",
                              "rejected",
                          ].includes(current.value.state)
                        ? "skipped"
                        : "pending";
            return { name: phase.name, types, state };
        })
        .filter((phase) => phase.types.length);
});
watch(
    () => props.deployment,
    (value) => {
        current.value = value;
    },
);
const timing = ref<"now" | "schedule">("now");
const scheduleTimezone =
    Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
const confirm = useForm({
    reviewed: false,
    digest: props.deployment.digest,
    scheduled_for: "",
});
watch([timing, () => confirm.scheduled_for], () => {
    confirm.reviewed = false;
    confirm.clearErrors("scheduled_for");
});
watch(
    () => props.deployment.digest,
    (digest) => {
        confirm.digest = digest;
        confirm.reviewed = false;
    },
);
function submitConfirmation() {
    const scheduling =
        current.value.state === "awaiting_confirmation" &&
        !current.value.scheduled_for &&
        timing.value === "schedule";
    const time = new Date(confirm.scheduled_for);
    if (
        scheduling &&
        (!Number.isFinite(time.getTime()) || time.getTime() <= Date.now())
    ) {
        confirm.setError("scheduled_for", "Choose a future date and time.");
        return;
    }
    confirm
        .transform((data) => ({
            reviewed: data.reviewed,
            digest: data.digest,
            ...(scheduling
                ? {
                      scheduled_for: time.toISOString(),
                      schedule_timezone: scheduleTimezone,
                  }
                : {}),
        }))
        .post(
            `/deployments/${current.value.id}/${current.value.state === "awaiting_approval" ? "approve" : "confirm"}`,
        );
}
const reject = useForm({ reason: "" });
const cancel = useForm({});
const recovery = useForm({ idempotency_key: crypto.randomUUID() });
const reconciliation = useForm({ reason: "", remote_processes_stopped: false });
const expired = computed(
    () => new Date(current.value.expires_at).getTime() < clock.value,
);
let stopped = false;
let timer: ReturnType<typeof setTimeout>;
let polling = false;
function schedulePoll() {
    clearTimeout(timer);
    if (
        !stopped &&
        !polling &&
        (["queued", "running", "awaiting_approval", "scheduled"].includes(
            current.value.state,
        ) ||
            (current.value.scheduled_for &&
                current.value.state === "awaiting_confirmation"))
    ) {
        timer = setTimeout(poll, pollDelay());
    }
}
function pollDelay() {
    return current.value.scheduled_for &&
        ["scheduled", "awaiting_confirmation", "awaiting_approval"].includes(
            current.value.state,
        )
        ? 15000
        : 2500;
}
async function poll() {
    if (stopped || polling) return;
    polling = true;
    const id = current.value.id;
    try {
        const { data } = await axios.get(`/deployments/${id}/events`);
        if (!stopped && current.value.id === id) {
            current.value = { ...current.value, ...data };
            pollError.value = "";
        }
    } catch {
        if (!stopped && current.value.id === id)
            pollError.value =
                "Status refresh failed. Displayed data may be stale; reload before taking action.";
    } finally {
        polling = false;
        schedulePoll();
    }
}
watch(() => [current.value.id, current.value.state], schedulePoll, {
    immediate: true,
});
onUnmounted(() => {
    stopped = true;
    clearTimeout(timer);
    clearTimeout(copyTimer);
    clearInterval(tick);
});
async function copyCommit() {
    try {
        await navigator.clipboard.writeText(current.value.target_sha);
        copiedCommit.value = true;
        clearTimeout(copyTimer);
        copyTimer = setTimeout(() => {
            copiedCommit.value = false;
            copying.value = false;
        }, 2000);
    } catch {
        pollError.value =
            "Clipboard unavailable. Expand deployment details to select the full commit.";
    }
}
async function copyLogs() {
    try {
        await navigator.clipboard.writeText(
            current.value.steps
                .map((s) => `${label(s.type)}\n${s.output || ""}`)
                .join("\n\n"),
        );
        copying.value = true;
        clearTimeout(copyTimer);
        copyTimer = setTimeout(() => {
            copying.value = false;
            copiedCommit.value = false;
        }, 2000);
    } catch {
        pollError.value =
            "Clipboard unavailable. Select the log text to copy it.";
    }
}
</script>
<template>
    <Head :title="`DEP-${current.id}`" /><HubLayout
        :title="`DEP-${String(current.id).padStart(6, '0')}`"
        :subtitle="`${deployment.environment?.project?.name} / ${deployment.environment?.name} · requested by ${deployment.user?.name}`"
        ><template #actions
            ><Link
                :href="
                    deployment.environment?.deleted_at
                        ? '/projects'
                        : `/environments/${deployment.environment_id}`
                "
                class="btn"
                >← Back to project</Link
            ><HubStatus :state="current.state" /><a
                v-if="reviewing && (canConfirm || canApprove)"
                href="#deployment-confirmation"
                class="btn primary"
                >Review & confirm ↓</a
            ></template
        >
        <div v-if="pollError" class="notice warning" role="alert">
            {{ pollError }}
        </div>
        <div v-if="current.environment?.deleted_at" class="notice">
            This project registration was deleted. Deployment history remains
            available for review.
        </div>
        <div v-if="current.error" class="notice error" role="alert">
            {{ current.error }}
            <p v-if="current.code_updated">
                Code was updated before failure. Review completed steps and the
                remote application before taking remedial action.
            </p>
        </div>
        <section
            v-if="
                canRecover && current.state === 'failed' && current.code_updated
            "
            class="card"
        >
            <div class="card-heading"><h2>Recover the failed pipeline</h2></div>
            <div class="card-content">
                <p>
                    After resolving the failure, prepare a fresh review at this
                    exact commit. Recovery reruns every selected pipeline step
                    and enabled hooks from the beginning without a Git merge.
                    Production recovery requires fresh independent approval.
                </p>
                <HubErrors :errors="recovery.errors" />
                <div class="form-actions">
                    <button
                        class="btn primary"
                        :disabled="recovery.processing"
                        @click="
                            recovery.post(`/deployments/${current.id}/recover`)
                        "
                    >
                        Prepare recovery preview
                    </button>
                </div>
            </div>
        </section>
        <div v-if="current.candidate.recovery_of" class="notice warning">
            Pipeline recovery for
            <Link :href="`/deployments/${current.candidate.recovery_of}`"
                >DEP-{{
                    String(current.candidate.recovery_of).padStart(6, "0")
                }}</Link
            >. Git remains at the reviewed commit; every selected pipeline step
            and enabled hook runs again using the configuration shown below.
        </div>
        <div
            v-if="!workerOnline && current.state === 'queued'"
            class="notice warning"
        >
            Worker heartbeat is offline or unconfirmed. This job is queued; it
            has not been reported as running.
        </div>
        <div
            v-if="current.scheduled_for"
            class="notice"
            :class="{ success: current.state === 'scheduled' }"
        >
            <strong
                >Scheduled for
                {{
                    date(current.scheduled_for, current.schedule_timezone)
                }}</strong
            >
            <p>
                Time zone: {{ current.schedule_timezone }}. Publishes the exact
                reviewed commit and workflow.
                {{
                    current.state === "scheduled"
                        ? "Confirmed; waiting for automatic dispatch."
                        : current.state === "awaiting_approval"
                          ? "Independent approval must be completed before this time."
                          : current.state === "awaiting_confirmation"
                            ? "Confirm below to activate the schedule."
                            : ""
                }}
            </p>
        </div>
        <div
            v-if="
                expired &&
                [
                    'awaiting_confirmation',
                    'awaiting_approval',
                    'scheduled',
                    'queued',
                ].includes(current.state)
            "
            class="notice warning"
        >
            Candidate has expired. Prepare a new deployment; this candidate
            cannot execute.
        </div>
        <div class="deployment-review-layout" :class="{ reviewing }">
            <div class="review-main">
                <section class="card deployment-summary">
                    <div class="card-heading">
                        <h2>
                            {{
                                reviewing
                                    ? "Deployment review"
                                    : "Deployment details"
                            }}
                        </h2>
                        <span class="badge"
                            >Pipeline v{{
                                current.candidate.pipeline_version
                            }}</span
                        >
                    </div>
                    <div class="meta-grid">
                        <div>
                            <span class="meta-label">Target commit</span>
                            <div class="deployment-result">
                                <code :title="current.target_sha">{{
                                    short(current.target_sha)
                                }}</code
                                ><button class="btn small" @click="copyCommit">
                                    {{ copiedCommit ? "Copied" : "Copy SHA" }}
                                </button>
                            </div>
                            <p v-if="targetMessage" class="commit-subject">
                                {{ targetMessage }}
                            </p>
                        </div>
                        <div>
                            <span class="meta-label">Deployment target</span
                            ><strong>{{ current.environment?.name }}</strong>
                            <p>
                                <code>{{ current.candidate.branch }}</code> ·
                                {{ current.environment?.server?.name }}
                            </p>
                            <span
                                v-if="current.candidate.production"
                                class="badge"
                                >Production</span
                            >
                        </div>
                        <div>
                            <span class="meta-label">{{
                                current.started_at
                                    ? "Execution time"
                                    : "Created"
                            }}</span>
                            <strong v-if="current.started_at">{{
                                duration(
                                    current.started_at,
                                    current.finished_at,
                                    clock,
                                )
                            }}</strong
                            ><span v-else>{{ date(current.created_at) }}</span>
                            <p v-if="activeStep" class="muted">
                                {{ label(activeStep.type) }}
                            </p>
                            <p v-if="current.finished_at" class="muted">
                                {{
                                    current.state === "unknown"
                                        ? "Status recorded"
                                        : "Finished"
                                }}
                                {{ date(current.finished_at) }}
                            </p>
                        </div>
                    </div>
                    <details class="candidate-details">
                        <summary>Commit, path & approval details</summary>
                        <div class="meta-grid">
                            <div>
                                <span class="meta-label">Starting HEAD</span
                                ><code>{{ current.starting_sha }}</code>
                            </div>
                            <div>
                                <span class="meta-label"
                                    >Exact target commit</span
                                ><code>{{ current.target_sha }}</code>
                            </div>
                            <div>
                                <span class="meta-label"
                                    >Repository directory</span
                                ><code>{{ current.candidate.path }}</code>
                            </div>
                            <div>
                                <span class="meta-label">Preflight</span
                                ><span class="meta-value"
                                    >{{
                                        current.preflight.clean === false
                                            ? "Local changes present"
                                            : "Clean"
                                    }}
                                    · matching branch · fast-forward only</span
                                >
                            </div>
                            <div>
                                <span class="meta-label">Candidate expiry</span
                                ><span class="meta-value">{{
                                    date(current.expires_at)
                                }}</span>
                            </div>
                            <div>
                                <span class="meta-label">Approval</span
                                ><span class="meta-value">{{
                                    current.approval
                                        ? `${current.approval.outcome} by member #${current.approval.user_id}`
                                        : current.candidate.requires_approval ||
                                            current.candidate.production
                                          ? "Independent review required"
                                          : "Direct staging confirmation"
                                }}</span>
                                <p
                                    v-if="current.approval?.reason"
                                    class="muted"
                                >
                                    {{ current.approval.reason }}
                                </p>
                            </div>
                        </div>
                    </details>
                </section>
                <component
                    :is="reviewing ? 'section' : 'details'"
                    v-if="hooks.length || current.preflight.clean === false"
                    class="card reviewed-workflow"
                >
                    <summary v-if="!reviewing" class="card-heading">
                        <strong>Reviewed deployment workflow</strong>
                        <span class="badge">{{ hooks.length }} enabled</span>
                    </summary>
                    <div v-else class="card-heading">
                        <h2>Reviewed deployment workflow</h2>
                        <span class="badge">{{ hooks.length }} enabled</span>
                    </div>
                    <div class="card-content">
                        <div
                            v-if="current.preflight.clean === false"
                            class="notice warning"
                        >
                            <p>
                                Local changes are present. Git preserves
                                unrelated changes and stops if the update would
                                overwrite them. Only enabled reset or cleanup
                                steps discard changes.
                            </p>
                            <pre class="hook-review mono">{{
                                current.preflight.changes
                            }}</pre>
                        </div>
                        <template
                            v-for="phase in ['pre', 'post'] as const"
                            :key="phase"
                        >
                            <h3
                                v-if="
                                    hooks.some((item) => item.phase === phase)
                                "
                                class="workflow-phase-title"
                            >
                                {{
                                    phase === "pre"
                                        ? "Pre deploy"
                                        : "Post deploy"
                                }}
                            </h3>
                            <details
                                class="hook-details hook-reviewed"
                                v-for="hook in hooks.filter(
                                    (item) => item.phase === phase,
                                )"
                                :key="hook.id"
                            >
                                <summary>{{ hook.name }}</summary>
                                <pre class="hook-review mono">{{
                                    hook.script
                                }}</pre>
                            </details>
                        </template>
                        <details class="hook-details">
                            <summary>Full execution order</summary>
                            <p class="muted">{{ plannedSteps }}</p>
                        </details>
                    </div>
                </component>
                <section
                    v-if="
                        current.state === 'awaiting_confirmation' ||
                        current.state === 'awaiting_approval'
                    "
                    class="card"
                >
                    <div class="card-heading">
                        <h2>
                            <template v-if="current.candidate.recovery_of"
                                >Review same-commit pipeline recovery</template
                            >
                            <template v-else
                                >Review {{ current.preflight.count }} incoming
                                commit{{
                                    current.preflight.count === 1 ? "" : "s"
                                }}</template
                            >
                        </h2>
                    </div>
                    <div
                        v-for="commit in current.preflight.commits"
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
                    <div
                        v-if="!current.preflight.commits?.length"
                        class="card-content muted"
                    >
                        The checkout is at the reviewed commit. Enabled workflow
                        actions will still run.
                    </div>
                </section>
            </div>
            <aside
                v-if="reviewing"
                class="card confirmation-card"
                id="deployment-confirmation"
            >
                <div class="card-heading">
                    <h2>
                        {{
                            current.state === "awaiting_approval"
                                ? "Independent approval"
                                : "Confirm deployment"
                        }}
                    </h2>
                </div>
                <div class="card-content">
                    <p class="muted">
                        {{ short(current.target_sha) }} →
                        {{ current.environment?.name }} /
                        {{ current.candidate.branch }}
                    </p>
                    <form
                        v-if="
                            (canConfirm &&
                                current.state === 'awaiting_confirmation') ||
                            (canApprove &&
                                current.state === 'awaiting_approval')
                        "
                        @submit.prevent="submitConfirmation"
                    >
                        <HubErrors :errors="confirm.errors" />
                        <div
                            v-if="
                                current.state === 'awaiting_confirmation' &&
                                !current.scheduled_for
                            "
                            class="field"
                        >
                            <label>Deployment timing</label>
                            <div
                                class="stack"
                                role="radiogroup"
                                aria-label="Deployment timing"
                            >
                                <label class="check"
                                    ><input
                                        v-model="timing"
                                        type="radio"
                                        value="now"
                                        name="deployment-timing"
                                    />Deploy now</label
                                >
                                <label class="check"
                                    ><input
                                        v-model="timing"
                                        type="radio"
                                        value="schedule"
                                        name="deployment-timing"
                                    />Schedule</label
                                >
                            </div>
                            <div v-if="timing === 'schedule'" class="field">
                                <label for="scheduled_for"
                                    >Deployment date and time ({{
                                        scheduleTimezone
                                    }})</label
                                >
                                <input
                                    id="scheduled_for"
                                    v-model="confirm.scheduled_for"
                                    type="datetime-local"
                                    required
                                />
                                <small
                                    >Choose a time within 30 days. The reviewed
                                    commit and workflow are used. Production
                                    requires approval before this time.</small
                                >
                                <p v-if="confirm.scheduled_for" class="muted">
                                    Scheduled for
                                    {{
                                        date(
                                            confirm.scheduled_for,
                                            scheduleTimezone,
                                        )
                                    }}
                                    ({{ scheduleTimezone }}).
                                </p>
                            </div>
                        </div>
                        <p
                            v-if="
                                timing === 'schedule' || current.scheduled_for
                            "
                            class="muted"
                        >
                            Dispatch is checked every minute, with a one-hour
                            execution window. Changed checkout, branch tip,
                            configuration or permissions stop deployment.
                        </p>
                        <div
                            v-if="
                                (timing === 'schedule' ||
                                    current.scheduled_for) &&
                                !scheduler?.online
                            "
                            class="notice warning"
                        >
                            Scheduler heartbeat is offline or unconfirmed. Keep
                            <code>php artisan schedule:work</code> and the queue
                            worker running.
                        </div>
                        <label class="check"
                            ><input
                                v-model="confirm.reviewed"
                                type="checkbox"
                                required
                            />I reviewed the commit, target, enabled workflow,
                            local changes and deployment timing.</label
                        >
                        <div class="form-actions">
                            <button
                                class="btn primary"
                                :disabled="
                                    confirm.processing ||
                                    !confirm.reviewed ||
                                    expired
                                "
                            >
                                {{
                                    current.state === "awaiting_approval"
                                        ? "Approve exact candidate"
                                        : current.candidate.requires_approval ||
                                            current.candidate.production
                                          ? "Request independent approval"
                                          : current.scheduled_for ||
                                              timing === "schedule"
                                            ? "Schedule deployment"
                                            : "Deploy now"
                                }}
                            </button>
                        </div>
                    </form>
                    <form
                        v-if="
                            canApprove && current.state === 'awaiting_approval'
                        "
                        @submit.prevent="
                            reject.post(`/deployments/${current.id}/reject`)
                        "
                    >
                        <HubErrors :errors="reject.errors" />
                        <div class="field">
                            <label for="reason">Rejection reason</label
                            ><input
                                id="reason"
                                v-model="reject.reason"
                                required
                                maxlength="200"
                                placeholder="Describe what needs to change"
                            />
                        </div>
                        <div class="form-actions">
                            <button
                                class="btn danger"
                                :disabled="reject.processing"
                            >
                                Reject request
                            </button>
                        </div>
                    </form>
                    <p
                        v-if="
                            current.state === 'awaiting_approval' && !canApprove
                        "
                        class="muted"
                        style="margin-top: 15px"
                    >
                        An independent member with approval permission must
                        review this candidate.
                    </p>
                </div>
            </aside>
        </div>
        <section
            v-if="
                current.steps.length ||
                !['awaiting_confirmation', 'awaiting_approval'].includes(
                    current.state,
                )
            "
            class="card"
        >
            <div class="card-heading">
                <div>
                    <h2>Deployment progress</h2>
                    <p>
                        Step status updates live. Sanitized output appears when
                        each command completes.
                    </p>
                </div>
                <button class="btn small" @click="copyLogs">
                    {{ copying ? "Copied" : "Copy logs" }}
                </button>
            </div>
            <ol class="phase-progress" aria-label="Deployment stages">
                <li
                    v-for="(phase, index) in phases"
                    :key="phase.name"
                    :data-state="phase.state"
                >
                    <span class="phase-number">{{
                        phase.state === "succeeded" ? "✓" : index + 1
                    }}</span>
                    <div>
                        <strong>{{ phase.name }}</strong
                        ><small>{{
                            phase.state === "pending"
                                ? "Waiting"
                                : phase.state === "skipped"
                                  ? "Not run"
                                  : phase.state.replace(/_/g, " ")
                        }}</small>
                    </div>
                </li>
            </ol>
            <div v-if="!current.steps.length" class="empty">
                <h2>Execution has not started</h2>
                <p>
                    Steps appear when a queue worker claims and revalidates the
                    candidate.
                </p>
            </div>
            <ol v-else class="steps">
                <li
                    v-for="(s, index) in current.steps"
                    :key="s.id"
                    class="step"
                    :data-state="s.state"
                >
                    <div class="step-header">
                        <div class="deployment-result">
                            <span class="step-number">{{
                                s.state === "succeeded" ? "✓" : index + 1
                            }}</span
                            ><strong>{{ label(s.type) }}</strong>
                        </div>
                        <div class="deployment-result">
                            <span class="muted">{{
                                duration(
                                    s.started_at,
                                    s.finished_at || current.finished_at,
                                    clock,
                                )
                            }}</span
                            ><HubStatus :state="s.state" />
                        </div>
                    </div>
                    <small class="muted"
                        >{{ date(s.started_at)
                        }}<span
                            v-if="
                                s.exit_code !== null &&
                                s.exit_code !== undefined
                            "
                        >
                            · exit {{ s.exit_code }}</span
                        ></small
                    >
                    <details
                        v-if="s.output"
                        class="step-output"
                        :open="['failed', 'unknown'].includes(s.state)"
                    >
                        <summary>
                            {{
                                ["failed", "unknown"].includes(s.state)
                                    ? "Failure output"
                                    : "View output"
                            }}
                        </summary>
                        <pre class="log">{{ s.output }}</pre>
                    </details>
                    <p
                        v-else-if="s.state === 'running'"
                        class="muted step-output"
                    >
                        Command is running. Output appears when it completes.
                    </p>
                </li>
            </ol>
        </section>
        <div
            v-if="
                canConfirm &&
                [
                    'awaiting_confirmation',
                    'awaiting_approval',
                    'scheduled',
                    'queued',
                    'running',
                ].includes(current.state)
            "
            class="stack"
        >
            <button
                class="btn danger"
                :disabled="cancel.processing || current.cancel_requested"
                @click="cancel.post(`/deployments/${current.id}/cancel`)"
            >
                {{
                    current.cancel_requested
                        ? "Cancellation requested"
                        : "Request cancellation"
                }}</button
            ><span v-if="current.state === 'running'" class="muted"
                >Current remote command may continue until the next step
                boundary.</span
            >
        </div>
        <section
            v-if="current.state === 'unknown' && canReconcile"
            class="card"
        >
            <div class="card-heading">
                <h2>Administrator reconciliation</h2>
            </div>
            <form
                class="card-content"
                @submit.prevent="
                    reconciliation.post(`/deployments/${current.id}/reconcile`)
                "
            >
                <div class="notice warning">
                    Verify remote processes have stopped and inspect the
                    application manually. Reconciliation checks the repository
                    and releases quarantine; it does not mark the pipeline
                    successful.
                </div>
                <HubErrors :errors="reconciliation.errors" />
                <div class="field">
                    <label for="reconcile_reason">Resolution notes</label
                    ><input
                        id="reconcile_reason"
                        v-model="reconciliation.reason"
                        required
                        maxlength="200"
                    />
                </div>
                <label class="check"
                    ><input
                        v-model="reconciliation.remote_processes_stopped"
                        type="checkbox"
                        required
                    />I stopped the worker and verified remote deployment
                    processes are no longer running.</label
                >
                <div class="form-actions">
                    <button
                        class="btn primary"
                        :disabled="
                            reconciliation.processing ||
                            !reconciliation.remote_processes_stopped
                        "
                    >
                        Check repository & release quarantine
                    </button>
                </div>
            </form>
        </section>
    </HubLayout>
</template>

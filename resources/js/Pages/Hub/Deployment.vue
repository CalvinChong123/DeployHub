<script setup lang="ts">
import { ref, watch, onUnmounted, computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import axios from "axios";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubStatus from "@/Components/HubStatus.vue";
import HubErrors from "@/Components/HubErrors.vue";
import { date, short, stepLabel, type Deployment } from "@/hub-types";
const props = defineProps<{
    deployment: Deployment;
    canConfirm: boolean;
    canApprove: boolean;
    canRecover: boolean;
    canReconcile: boolean;
    workerOnline: boolean;
}>();
const current = ref(props.deployment);
const pollError = ref("");
const copying = ref(false);
watch(
    () => props.deployment,
    (value) => {
        current.value = value;
    },
);
const confirm = useForm({ reviewed: false, digest: props.deployment.digest });
const reject = useForm({ reason: "" });
const cancel = useForm({});
const recovery = useForm({ idempotency_key: crypto.randomUUID() });
const reconciliation = useForm({ reason: "", remote_processes_stopped: false });
const expired = computed(
    () => new Date(current.value.expires_at).getTime() < Date.now(),
);
let stopped = false;
let timer: ReturnType<typeof setTimeout>;
async function poll() {
    if (
        stopped ||
        !["queued", "running", "awaiting_approval"].includes(
            current.value.state,
        )
    )
        return;
    try {
        const { data } = await axios.get(
            `/deployments/${current.value.id}/events`,
        );
        current.value = { ...current.value, ...data };
        pollError.value = "";
    } catch {
        pollError.value =
            "Status refresh failed. Displayed data may be stale; reload before taking action.";
    }
    if (!stopped) timer = setTimeout(poll, 2500);
}
watch(
    () => current.value.state,
    () => {
        clearTimeout(timer);
        timer = setTimeout(poll, 2500);
    },
    { immediate: true },
);
onUnmounted(() => {
    stopped = true;
    clearTimeout(timer);
});
async function copyLogs() {
    try {
        await navigator.clipboard.writeText(
            current.value.steps
                .map((s) => `${stepLabel[s.type] || s.type}\n${s.output || ""}`)
                .join("\n\n"),
        );
        copying.value = true;
        setTimeout(() => (copying.value = false), 2000);
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
                :href="`/environments/${deployment.environment_id}`"
                class="btn"
                >← Back to project</Link
            ><HubStatus :state="current.state"
        /></template>
        <div v-if="pollError" class="notice warning" role="alert">
            {{ pollError }}
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
                    from the beginning without updating Git. Production recovery
                    requires fresh independent approval.
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
            runs again using the configuration shown below.
        </div>
        <div
            v-if="!workerOnline && current.state === 'queued'"
            class="notice warning"
        >
            Worker heartbeat is offline or unconfirmed. This job is queued; it
            has not been reported as running.
        </div>
        <div
            v-if="
                expired &&
                [
                    'awaiting_confirmation',
                    'awaiting_approval',
                    'queued',
                ].includes(current.state)
            "
            class="notice warning"
        >
            Candidate has expired. Create a fresh preview; this candidate cannot
            execute.
        </div>
        <section class="card">
            <div class="card-heading">
                <h2>Immutable deployment candidate</h2>
                <span class="badge"
                    >Pipeline v{{ current.candidate.pipeline_version }}</span
                >
            </div>
            <div class="meta-grid">
                <div>
                    <span class="meta-label">Starting HEAD</span
                    ><code>{{ current.starting_sha }}</code>
                </div>
                <div>
                    <span class="meta-label">Exact target commit</span
                    ><code>{{ current.target_sha }}</code>
                </div>
                <div>
                    <span class="meta-label">Branch / directory</span
                    ><code>{{ current.candidate.branch }}</code>
                    <p class="mono muted">{{ current.candidate.path }}</p>
                </div>
                <div>
                    <span class="meta-label">Preflight</span
                    ><span class="meta-value"
                        >Clean · matching branch · fast-forward only</span
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
                    <p v-if="current.approval?.reason" class="muted">
                        {{ current.approval.reason }}
                    </p>
                </div>
            </div>
        </section>
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
                        >Review {{ current.preflight.count }} incoming commit{{
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
            <div class="card-content">
                <p class="muted">
                    Planned steps: preflight →
                    {{ current.candidate.recovery_of ? "" : "fast-forward → " }}
                    {{
                        current.candidate.pipeline
                            .map((s) => stepLabel[s])
                            .join(" → ")
                    }}{{ current.candidate.pipeline.length ? " → " : "" }}verify
                    target HEAD.
                </p>
                <form
                    v-if="
                        (canConfirm &&
                            current.state === 'awaiting_confirmation') ||
                        (canApprove && current.state === 'awaiting_approval')
                    "
                    @submit.prevent="
                        confirm.post(
                            `/deployments/${current.id}/${current.state === 'awaiting_approval' ? 'approve' : 'confirm'}`,
                        )
                    "
                >
                    <HubErrors :errors="confirm.errors" /><label class="check"
                        ><input
                            v-model="confirm.reviewed"
                            type="checkbox"
                            required
                        />I reviewed the exact commit, environment and approved
                        pipeline.</label
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
                                      : "Confirm staging deployment"
                            }}
                        </button>
                    </div>
                </form>
                <form
                    v-if="canApprove && current.state === 'awaiting_approval'"
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
                    v-if="current.state === 'awaiting_approval' && !canApprove"
                    class="muted"
                    style="margin-top: 15px"
                >
                    An independent member with approval permission must review
                    this candidate.
                </p>
            </div>
        </section>
        <section class="card">
            <div class="card-heading">
                <div>
                    <h2>Deployment console</h2>
                    <p>
                        Step status updates live. Sanitized output appears when
                        each command completes.
                    </p>
                </div>
                <button class="btn small" @click="copyLogs">
                    {{ copying ? "Copied" : "Copy logs" }}
                </button>
            </div>
            <div v-if="!current.steps.length" class="empty">
                <h2>Execution has not started</h2>
                <p>
                    Steps appear when a queue worker claims and revalidates the
                    candidate.
                </p>
            </div>
            <ol v-else class="steps">
                <li v-for="s in current.steps" :key="s.id" class="step">
                    <div class="step-header">
                        <strong>{{ stepLabel[s.type] || s.type }}</strong
                        ><HubStatus :state="s.state" />
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
                    <pre v-if="s.output" class="log">{{ s.output }}</pre>
                </li>
            </ol>
        </section>
        <div
            v-if="
                canConfirm &&
                [
                    'awaiting_confirmation',
                    'awaiting_approval',
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

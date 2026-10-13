<script setup lang="ts">
import { ref, watch } from "vue";
import type { DeploymentHook } from "@/hub-types";
const props = defineProps<{ form: any }>();
const builtin = (hook: DeploymentHook) => !!hook.type && hook.type !== "custom";
const expanded = ref(new Set<string>());
watch(
    () => props.form.errors,
    (errors) => {
        for (const key of Object.keys(errors || {})) {
            const match = key.match(/^hooks\.(\d+)\./);
            if (match && props.form.hooks[Number(match[1])])
                expanded.value.add(props.form.hooks[Number(match[1])].id);
        }
    },
    { deep: true },
);
function add(phase: "pre" | "post") {
    const id = crypto.randomUUID();
    props.form.hooks.push({
        id,
        name: "",
        phase,
        script: "",
        enabled: false,
        type: "custom",
    } satisfies DeploymentHook);
    expanded.value.add(id);
}
function move(hook: DeploymentHook, direction: number) {
    const hooks = props.form.hooks as DeploymentHook[];
    const phase = hooks.filter((item) => item.phase === hook.phase);
    const neighbor = phase[phase.indexOf(hook) + direction];
    if (!neighbor) return;
    const from = hooks.indexOf(hook),
        to = hooks.indexOf(neighbor);
    [hooks[from], hooks[to]] = [hooks[to], hooks[from]];
}
</script>
<template>
    <div class="field full">
        <label>Deployment workflow</label>
        <small
            >Run Shell scripts as the deployment user in the repository
            directory. Add a hook, then enable its checkbox. Each hook uses a
            separate sh -e session; a failing command stops deployment. Use
            server environment variables for secrets.</small
        >
        <section
            v-for="phase in ['pre', 'post'] as const"
            :key="phase"
            class="hook-phase"
        >
            <div class="hook-heading">
                <div>
                    <strong>{{
                        phase === "pre" ? "Pre deploy" : "Post deploy"
                    }}</strong>
                    <p class="muted">
                        {{
                            phase === "pre"
                                ? "Before Git updates. Enable Hard reset only when you want tracked local changes discarded."
                                : "After Git updates. Defaults and custom hooks run in the order shown."
                        }}
                    </p>
                </div>
                <button
                    type="button"
                    class="btn"
                    :disabled="form.hooks.length >= 26"
                    @click="add(phase)"
                >
                    + Add {{ phase === "pre" ? "Pre" : "Post" }} hook
                </button>
            </div>
            <p
                v-if="
                    !form.hooks.some(
                        (hook: DeploymentHook) => hook.phase === phase,
                    )
                "
                class="muted"
            >
                No hooks added.
            </p>
            <div
                v-for="(hook, index) in (form.hooks as DeploymentHook[]).filter(
                    (item) => item.phase === phase,
                )"
                :key="hook.id"
                class="hook-editor"
            >
                <div class="hook-heading">
                    <label class="check"
                        ><input
                            v-model="hook.enabled"
                            type="checkbox"
                            :aria-label="`Enable ${hook.name || phase + ' hook ' + (index + 1)}`"
                        />{{ builtin(hook) ? hook.name : "" }}</label
                    >
                    <div v-if="!builtin(hook)" class="hook-name">
                        <label class="sr-only" :for="`name-${hook.id}`"
                            >Hook name</label
                        >
                        <input
                            :id="`name-${hook.id}`"
                            v-model="hook.name"
                            maxlength="80"
                            placeholder="Hook name"
                        />
                    </div>
                    <div class="hook-controls">
                        <button
                            type="button"
                            class="btn"
                            :disabled="index === 0"
                            :aria-label="`Move ${hook.name || 'hook'} up`"
                            @click="move(hook, -1)"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            class="btn"
                            :disabled="
                                index ===
                                form.hooks.filter(
                                    (item: DeploymentHook) =>
                                        item.phase === phase,
                                ).length -
                                    1
                            "
                            :aria-label="`Move ${hook.name || 'hook'} down`"
                            @click="move(hook, 1)"
                        >
                            ↓
                        </button>
                        <button
                            v-if="!builtin(hook)"
                            type="button"
                            class="btn danger"
                            @click="
                                form.hooks.splice(form.hooks.indexOf(hook), 1)
                            "
                        >
                            Remove
                        </button>
                    </div>
                </div>
                <details
                    class="hook-details"
                    :open="expanded.has(hook.id)"
                    @toggle="
                        ($event.target as HTMLDetailsElement).open
                            ? expanded.add(hook.id)
                            : expanded.delete(hook.id)
                    "
                >
                    <summary>
                        {{
                            builtin(hook) ? "View command" : "Edit Shell script"
                        }}
                    </summary>
                    <div class="form-grid">
                        <div v-if="builtin(hook)" class="field full">
                            <code class="hook-review">{{ hook.script }}</code>
                            <small v-if="hook.type === 'hard_reset'"
                                >Resets tracked files to the current HEAD before
                                the reviewed Git update. Untracked files are
                                generally retained; paths obstructing tracked
                                files may be removed.</small
                            >
                            <small v-if="hook.type === 'health'"
                                >Uses the HTTPS health check URL below.</small
                            >
                        </div>
                        <div v-else class="field full">
                            <label :for="`script-${hook.id}`"
                                >Shell script</label
                            ><textarea
                                :id="`script-${hook.id}`"
                                v-model="hook.script"
                                class="mono"
                                maxlength="10000"
                                rows="5"
                                spellcheck="false"
                                :placeholder="
                                    phase === 'pre'
                                        ? 'git reset --hard HEAD'
                                        : 'php artisan migrate --force'
                                "
                            />
                        </div>
                    </div>
                </details>
            </div>
        </section>
    </div>
</template>

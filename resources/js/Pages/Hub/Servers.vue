<script setup lang="ts">
import { ref, watch } from "vue";
import { Head, useForm, usePage, router } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubStatus from "@/Components/HubStatus.vue";
import HubErrors from "@/Components/HubErrors.vue";
import { date, type Server } from "@/hub-types";
defineProps<{ servers: Server[] }>();
const page = usePage<any>();
const adding = ref(false);
const selected = ref<Server | null>(null);
const rotating = ref(false);
const editing = ref(false);
const connection = useForm({ name: "", host: "", port: 22, username: "" });
const form = useForm({
    name: "",
    host: "",
    port: 22,
    username: "",
    private_key: "",
    passphrase: "",
});
const action = useForm({});
const verification = useForm({ fingerprint: "", verified_out_of_band: false });
const rotate = useForm({ private_key: "", passphrase: "" });
watch(
    () => page.props.flash?.fingerprint,
    (value) => {
        if (value && value.server_id === selected.value?.id)
            verification.fingerprint = value.value;
    },
);
function select(server: Server) {
    selected.value = server;
    rotating.value = false;
    editing.value = false;
    connection.name = server.name;
    connection.host = server.host || "";
    connection.port = server.port || 22;
    connection.username = server.username || "";
    verification.reset();
    form.reset("private_key", "passphrase");
    rotate.reset();
}
function submit() {
    form.post("/servers", {
        onFinish: () => form.reset("private_key", "passphrase"),
        onSuccess: () => {
            if (!page.props.flash?.error) {
                adding.value = false;
                form.reset();
            }
        },
    });
}
function remove(server: Server) {
    if (window.confirm(`Delete ${server.name} and its encrypted credentials?`))
        router.delete(`/servers/${server.id}`);
}
</script>
<template>
    <Head title="Servers" /><HubLayout
        title="Servers"
        subtitle="Encrypted credentials. Explicit host trust. No shared private keys."
        ><template #actions
            ><button
                class="btn primary"
                @click="
                    adding = !adding;
                    selected = null;
                "
            >
                ＋ Register server
            </button></template
        >
        <section class="card">
            <div class="card-heading">
                <h2>
                    SSH connections
                    <span class="count-badge">{{ servers.length }}</span>
                </h2>
                <span class="muted" style="font-size: 11px"
                    >Admin access only</span
                >
            </div>
            <div v-if="!servers.length" class="empty">
                <div class="empty-symbol">▥</div>
                <h2>Connect your first server</h2>
                <p>
                    Register a dedicated deployment account, verify its host
                    fingerprint and test authentication.
                </p>
                <button class="btn primary" @click="adding = true">
                    Register SSH server
                </button>
            </div>
            <div v-else class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>Server</th>
                            <th>SSH account</th>
                            <th>Connection</th>
                            <th>Last test</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="server in servers" :key="server.id">
                            <td>
                                <strong>{{ server.name }}</strong>
                                <p>
                                    <code
                                        >{{ server.host }}:{{
                                            server.port
                                        }}</code
                                    >
                                </p>
                            </td>
                            <td>
                                <code>{{ server.username }}</code>
                            </td>
                            <td><HubStatus :state="server.status" /></td>
                            <td class="muted">{{ date(server.checked_at) }}</td>
                            <td>
                                <button
                                    class="btn small"
                                    @click="
                                        select(server);
                                        adding = false;
                                    "
                                >
                                    Manage →
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section v-if="adding" class="card">
            <div class="card-heading"><h2>Register SSH server</h2></div>
            <form
                class="card-content"
                autocomplete="off"
                @submit.prevent="submit"
            >
                <HubErrors :errors="form.errors" />
                <div class="form-grid">
                    <div class="field">
                        <label for="server_name">Server label</label
                        ><input
                            id="server_name"
                            v-model="form.name"
                            required
                            placeholder="Staging VPS"
                        />
                    </div>
                    <div class="field">
                        <label for="host">Hostname or IP</label
                        ><input
                            id="host"
                            v-model="form.host"
                            required
                            placeholder="staging.example.com"
                        />
                    </div>
                    <div class="field">
                        <label for="port">SSH port</label
                        ><input
                            id="port"
                            v-model="form.port"
                            type="number"
                            min="1"
                            max="65535"
                            required
                        />
                    </div>
                    <div class="field">
                        <label for="username">Deployment username</label
                        ><input
                            id="username"
                            v-model="form.username"
                            required
                            placeholder="deploy"
                        />
                    </div>
                    <div class="field full">
                        <label for="private_key">SSH private key</label
                        ><textarea
                            id="private_key"
                            v-model="form.private_key"
                            required
                            rows="5"
                            spellcheck="false"
                            autocomplete="off"
                            class="mono"
                            placeholder="Paste your dedicated deployment key"
                        /><small
                            >Encrypted at rest. Never returned after saving.
                            Input clears after every submission.</small
                        >
                    </div>
                    <div class="field full">
                        <label for="passphrase">Key passphrase (optional)</label
                        ><input
                            id="passphrase"
                            v-model="form.passphrase"
                            type="password"
                            autocomplete="new-password"
                        />
                    </div>
                </div>
                <div class="form-actions">
                    <button
                        type="button"
                        class="btn"
                        @click="
                            adding = false;
                            form.reset();
                        "
                    >
                        Cancel</button
                    ><button class="btn primary" :disabled="form.processing">
                        Save encrypted credential
                    </button>
                </div>
            </form>
        </section>
        <section v-if="selected" class="card">
            <div class="card-heading">
                <h2>{{ selected.name }} / connection controls</h2>
                <button
                    class="icon-button"
                    @click="selected = null"
                    aria-label="Close server controls"
                >
                    ✕
                </button>
            </div>
            <div class="card-content">
                <div
                    v-if="servers.find((s) => s.id === selected?.id)?.error"
                    class="notice error"
                >
                    {{ servers.find((s) => s.id === selected?.id)?.error }}
                </div>
                <div class="notice">
                    Current pinned host key:
                    <code>{{
                        servers.find((s) => s.id === selected?.id)
                            ?.fingerprint || "Not verified"
                    }}</code>
                </div>
                <div class="stack">
                    <button
                        class="btn"
                        :disabled="action.processing"
                        @click="action.post(`/servers/${selected.id}/discover`)"
                    >
                        Discover fingerprint</button
                    ><button
                        class="btn primary"
                        :disabled="action.processing"
                        @click="
                            action.post(
                                `/servers/${selected.id}/connection-test`,
                            )
                        "
                    >
                        Test authentication</button
                    ><button class="btn" @click="rotating = !rotating">
                        Rotate credential</button
                    ><button class="btn danger" @click="remove(selected)">
                        Delete server
                    </button>
                </div>
                <form
                    v-if="verification.fingerprint"
                    style="margin-top: 24px"
                    @submit.prevent="
                        verification.post(`/servers/${selected!.id}/verify`, {
                            onSuccess: () => verification.reset(),
                        })
                    "
                >
                    <HubErrors :errors="verification.errors" />
                    <div class="notice warning">
                        Discovered fingerprint:
                        <code>{{ verification.fingerprint }}</code>
                    </div>
                    <label class="check"
                        ><input
                            v-model="verification.verified_out_of_band"
                            type="checkbox"
                            required
                        />I compared this fingerprint with a trusted source
                        outside DeployHub.</label
                    ><button
                        class="btn primary"
                        :disabled="
                            verification.processing ||
                            !verification.verified_out_of_band
                        "
                    >
                        Pin verified fingerprint
                    </button>
                </form>
                <form
                    v-if="rotating"
                    style="margin-top: 24px"
                    autocomplete="off"
                    @submit.prevent="
                        rotate.post(`/servers/${selected!.id}/rotate`, {
                            onFinish: () => rotate.reset(),
                        })
                    "
                >
                    <HubErrors :errors="rotate.errors" />
                    <div class="field">
                        <label for="rotate_key">Replacement private key</label
                        ><textarea
                            id="rotate_key"
                            v-model="rotate.private_key"
                            rows="5"
                            spellcheck="false"
                            required
                        />
                    </div>
                    <div class="field" style="margin-top: 15px">
                        <label for="rotate_pass">Replacement passphrase</label
                        ><input
                            id="rotate_pass"
                            v-model="rotate.passphrase"
                            type="password"
                            autocomplete="new-password"
                        />
                    </div>
                    <div class="form-actions">
                        <button
                            class="btn primary"
                            :disabled="rotate.processing"
                        >
                            Rotate encrypted key
                        </button>
                    </div>
                </form>
                <button
                    class="btn"
                    style="margin-top: 20px"
                    @click="editing = !editing"
                >
                    Edit connection metadata
                </button>
                <form
                    v-if="editing"
                    style="margin-top: 20px"
                    @submit.prevent="connection.put(`/servers/${selected!.id}`)"
                >
                    <HubErrors :errors="connection.errors" />
                    <div class="form-grid">
                        <div class="field">
                            <label for="edit_server_name">Server label</label
                            ><input
                                id="edit_server_name"
                                v-model="connection.name"
                                required
                            />
                        </div>
                        <div class="field">
                            <label for="edit_host">Hostname or IP</label
                            ><input
                                id="edit_host"
                                v-model="connection.host"
                                required
                            />
                        </div>
                        <div class="field">
                            <label for="edit_port">SSH port</label
                            ><input
                                id="edit_port"
                                v-model="connection.port"
                                type="number"
                                min="1"
                                max="65535"
                                required
                            />
                        </div>
                        <div class="field">
                            <label for="edit_username"
                                >Deployment username</label
                            ><input
                                id="edit_username"
                                v-model="connection.username"
                                required
                            />
                        </div>
                    </div>
                    <p class="muted" style="margin-top: 15px">
                        Changing the host, port or account clears host trust and
                        invalidates existing candidates.
                    </p>
                    <div class="form-actions">
                        <button
                            class="btn primary"
                            :disabled="connection.processing"
                        >
                            Save connection metadata
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </HubLayout>
</template>

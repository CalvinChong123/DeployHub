<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Head, useForm, usePage, router } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubStatus from "@/Components/HubStatus.vue";
import HubErrors from "@/Components/HubErrors.vue";
import { date, type Server } from "@/hub-types";
const props = defineProps<{ servers: Server[] }>();
const page = usePage<any>();
const adding = ref(false);
const selected = ref<Server | null>(null);
const rotating = ref(false);
const editing = ref(false);
const currentServer = computed(() =>
    props.servers.find((server) => server.id === selected.value?.id),
);
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
const verification = useForm({
    fingerprint: "",
    trust_method: "first_use",
    trust_first_use: false,
    verified_out_of_band: false,
});
const rotate = useForm({ private_key: "", passphrase: "" });
watch(
    () => page.props.flash?.fingerprint,
    (value) => {
        if (value && value.server_id === selected.value?.id) {
            verification.defaults(
                "trust_method",
                currentServer.value?.fingerprint ? "independent" : "first_use",
            );
            verification.reset();
            verification.fingerprint = value.value;
        }
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
    verification.defaults(
        "trust_method",
        server.fingerprint ? "independent" : "first_use",
    );
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
                            ?.fingerprint || "Not saved"
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
                        :disabled="
                            action.processing || !currentServer?.fingerprint
                        "
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
                <p
                    v-if="!currentServer?.fingerprint"
                    class="muted"
                    style="margin-top: 12px"
                >
                    Discover and save a trusted fingerprint to enable
                    authentication testing.
                </p>
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
                    <div v-if="!currentServer?.fingerprint" class="field">
                        <label for="trust_method"
                            >How to trust this server</label
                        >
                        <select
                            id="trust_method"
                            v-model="verification.trust_method"
                            @change="
                                verification.trust_first_use = false;
                                verification.verified_out_of_band = false;
                            "
                        >
                            <option value="first_use">
                                Trust on first use
                            </option>
                            <option value="independent">
                                Verify with a trusted source
                            </option>
                        </select>
                    </div>
                    <template
                        v-if="
                            verification.trust_method === 'first_use' &&
                            !currentServer?.fingerprint
                        "
                    >
                        <p class="muted" style="margin: 16px 0">
                            Accept this server's first discovered fingerprint
                            without comparing another source. Future connections
                            must match the saved fingerprint; changes will be
                            blocked.
                        </p>
                        <label class="check">
                            <input
                                v-model="verification.trust_first_use"
                                type="checkbox"
                                required
                            />
                            I trust {{ currentServer?.host }}:{{
                                currentServer?.port
                            }}
                            and accept this fingerprint for the first
                            connection.
                        </label>
                    </template>
                    <template v-else>
                        <p
                            v-if="currentServer?.fingerprint"
                            class="notice warning"
                            style="margin: 16px 0"
                        >
                            A fingerprint is already saved. Replacing it
                            requires verification with a trusted source outside
                            DeployHub.
                        </p>
                        <label class="check"
                            ><input
                                v-model="verification.verified_out_of_band"
                                type="checkbox"
                                required
                            />I compared this fingerprint with a trusted source
                            outside DeployHub.</label
                        >
                    </template>
                    <button
                        class="btn primary"
                        :disabled="
                            verification.processing ||
                            !(verification.trust_method === 'first_use' &&
                            !currentServer?.fingerprint
                                ? verification.trust_first_use
                                : verification.verified_out_of_band)
                        "
                    >
                        {{
                            verification.trust_method === "first_use" &&
                            !currentServer?.fingerprint
                                ? "Trust & save fingerprint"
                                : "Pin verified fingerprint"
                        }}
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

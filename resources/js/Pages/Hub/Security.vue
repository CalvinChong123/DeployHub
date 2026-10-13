<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubErrors from "@/Components/HubErrors.vue";
import AuthenticatorQrCode from "@/Components/AuthenticatorQrCode.vue";
import type { PageProps } from "@/types";
defineProps<{ enabled: boolean; pendingSecret?: string }>();
const page = usePage<PageProps>();
const setup = useForm({ password: "" });
const enable = useForm({ code: "" });
</script>
<template>
    <Head title="Account security" /><HubLayout
        title="Account security"
        subtitle="Protect your identity before managing hosts or running deployments."
        ><template #actions
            ><Link href="/automation" class="btn">AI deployment access</Link
            ><Link href="/profile" class="btn"
                >Edit profile & password</Link
            ></template
        >
        <div class="section-grid">
            <section class="card">
                <div class="card-heading">
                    <h2>Authenticator verification</h2>
                    <span class="badge">{{
                        enabled ? "Enabled" : "Required for privileged actions"
                    }}</span>
                </div>
                <div class="card-content">
                    <div v-if="enabled" class="notice success">
                        Two-factor authentication is active. Every new sign-in
                        requires an authenticator code.
                    </div>
                    <form
                        v-else-if="!pendingSecret"
                        @submit.prevent="
                            setup.post('/security/setup', {
                                onFinish: () => setup.reset(),
                            })
                        "
                    >
                        <HubErrors :errors="setup.errors" />
                        <p class="muted">
                            Use an authenticator application that supports
                            six-digit TOTP codes.
                        </p>
                        <div class="field" style="margin-top: 20px">
                            <label for="password">Confirm your password</label
                            ><input
                                id="password"
                                v-model="setup.password"
                                type="password"
                                required
                                autocomplete="current-password"
                            />
                        </div>
                        <div class="form-actions">
                            <button
                                class="btn primary"
                                :disabled="setup.processing"
                            >
                                Set up authenticator
                            </button>
                        </div>
                    </form>
                    <form
                        v-else
                        @submit.prevent="
                            enable.post('/security/enable', {
                                onFinish: () => enable.reset(),
                            })
                        "
                    >
                        <HubErrors :errors="enable.errors" />
                        <div class="authenticator-enrollment">
                            <AuthenticatorQrCode
                                :secret="pendingSecret!"
                                :account="page.props.auth.user.email"
                            />
                            <div>
                                <h3>Scan with your authenticator</h3>
                                <ol class="enrollment-instructions">
                                    <li>
                                        Open your authenticator application.
                                    </li>
                                    <li>
                                        Add an account and scan this QR code.
                                    </li>
                                    <li>
                                        Enter the six-digit code it generates
                                        below, then select Verify &amp; enable.
                                    </li>
                                </ol>
                                <p class="muted enrollment-account">
                                    DeployHub · {{ page.props.auth.user.email }}
                                </p>
                            </div>
                        </div>
                        <details class="manual-enrollment">
                            <summary>
                                Can't scan? Enter the setup key manually
                            </summary>
                            <p class="muted">
                                Add a time-based (TOTP) account named DeployHub
                                using this key, with six-digit codes.
                            </p>
                            <pre class="log setup-key">{{ pendingSecret }}</pre>
                        </details>
                        <div class="field">
                            <label for="code">Six-digit code</label
                            ><input
                                id="code"
                                v-model="enable.code"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                required
                                maxlength="6"
                                autocomplete="one-time-code"
                            />
                        </div>
                        <div class="form-actions">
                            <button
                                class="btn primary"
                                :disabled="enable.processing"
                            >
                                Verify & enable
                            </button>
                        </div>
                    </form>
                </div>
            </section>
            <section class="card">
                <div class="card-heading"><h2>Security practices</h2></div>
                <div class="card-content">
                    <ul class="help-list">
                        <li>
                            Save your authenticator backup in your password
                            manager.
                        </li>
                        <li>
                            Contact the installation owner if you lose your
                            authenticator. Recovery requires local
                            administrative access.
                        </li>
                        <li>
                            Keep DeployHub on a trusted machine or private
                            network.
                        </li>
                        <li>
                            Privileged actions require MFA; deployment jobs also
                            check enrollment.
                        </li>
                    </ul>
                </div>
            </section>
        </div></HubLayout
    >
</template>

<style scoped>
.authenticator-enrollment {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 24px;
    margin-bottom: 24px;
}
.authenticator-enrollment > div {
    flex: 1 1 220px;
    min-width: 0;
}
.enrollment-instructions {
    margin: 12px 0;
    padding-left: 20px;
    line-height: 1.8;
}
.enrollment-account {
    overflow-wrap: anywhere;
}
.manual-enrollment {
    margin-bottom: 24px;
}
.manual-enrollment summary {
    cursor: pointer;
    margin-bottom: 12px;
}
.setup-key {
    font-size: 16px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>

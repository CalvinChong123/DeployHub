<script setup lang="ts">
import { Head, useForm, Link } from "@inertiajs/vue3";
import HubErrors from "@/Components/HubErrors.vue";
import "../../../css/hub.css";
const form = useForm({ code: "" });
</script>
<template>
    <Head title="Verify authenticator" />
    <div class="auth-page">
        <div class="auth-card">
            <div class="brand">
                <span class="brand-mark">D</span>DeployHub<span
                    class="brand-dot"
                    >.</span
                >
            </div>
            <div class="card">
                <h1>Verify your identity</h1>
                <p class="subtitle">
                    Enter the current code from your authenticator.
                </p>
                <form
                    @submit.prevent="
                        form.post('/two-factor-challenge', {
                            onFinish: () => form.reset(),
                        })
                    "
                >
                    <HubErrors :errors="form.errors" />
                    <div class="field">
                        <label for="code">Authenticator code</label
                        ><input
                            id="code"
                            v-model="form.code"
                            required
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            autofocus
                        />
                    </div>
                    <button class="btn primary" :disabled="form.processing">
                        Verify & continue
                    </button>
                </form>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="link"
                    style="margin-top: 20px; font-size: 12px"
                    >Sign out</Link
                >
            </div>
        </div>
    </div>
</template>

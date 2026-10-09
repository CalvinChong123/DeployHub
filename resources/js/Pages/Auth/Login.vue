<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import HubErrors from "@/Components/HubErrors.vue";
import "../../../css/hub.css";
defineProps<{ canResetPassword?: boolean; status?: string }>();
const form = useForm({ email: "", password: "" });
</script>
<template>
    <Head title="Sign in" />
    <div class="auth-page">
        <div class="auth-card">
            <div class="brand">
                <span class="brand-mark">D</span>DeployHub<span
                    class="brand-dot"
                    >.</span
                >
            </div>
            <div class="card">
                <h1>Welcome back</h1>
                <p class="subtitle">
                    Sign in to your private deployment workspace.
                </p>
                <div v-if="status" class="notice success">{{ status }}</div>
                <form
                    @submit.prevent="
                        form.post('/login', {
                            onFinish: () => form.reset('password'),
                        })
                    "
                >
                    <HubErrors :errors="form.errors" />
                    <div class="field">
                        <label for="email">Email address</label
                        ><input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="you@example.com"
                        />
                    </div>
                    <div class="field">
                        <label for="password">Password</label
                        ><input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autocomplete="current-password"
                        />
                    </div>
                    <div style="text-align: right; margin-top: 10px">
                        <Link
                            v-if="canResetPassword"
                            href="/forgot-password"
                            class="link"
                            style="font-size: 11px"
                            >Forgot password?</Link
                        >
                    </div>
                    <button class="btn primary" :disabled="form.processing">
                        {{ form.processing ? "Signing in…" : "Sign in →" }}
                    </button>
                </form>
            </div>
            <p class="muted" style="text-align: center; font-size: 11px">
                Private access · Verified targets · Controlled deployments
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Head, useForm, usePage } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubErrors from "@/Components/HubErrors.vue";
const page = usePage<any>();
const profile = useForm({
    name: page.props.auth.user.name,
    email: page.props.auth.user.email,
});
const password = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});
defineProps<{ mustVerifyEmail?: boolean; status?: string }>();
</script>
<template>
    <Head title="My profile" /><HubLayout
        title="My profile"
        subtitle="Update your identity and sign-in password."
        ><section class="card">
            <div class="card-heading"><h2>Profile information</h2></div>
            <form
                class="card-content"
                @submit.prevent="profile.patch('/profile')"
            >
                <HubErrors :errors="profile.errors" />
                <div class="form-grid">
                    <div class="field">
                        <label for="name">Name</label
                        ><input id="name" v-model="profile.name" required />
                    </div>
                    <div class="field">
                        <label for="email">Email</label
                        ><input
                            id="email"
                            v-model="profile.email"
                            type="email"
                            required
                        />
                    </div>
                </div>
                <div class="form-actions">
                    <span v-if="profile.recentlySuccessful" class="muted"
                        >Saved</span
                    ><button class="btn primary" :disabled="profile.processing">
                        Save profile
                    </button>
                </div>
            </form>
        </section>
        <section class="card">
            <div class="card-heading"><h2>Change password</h2></div>
            <form
                class="card-content"
                @submit.prevent="
                    password.put('/password', {
                        onFinish: () => password.reset(),
                    })
                "
            >
                <HubErrors :errors="password.errors" />
                <div class="form-grid">
                    <div class="field full">
                        <label for="current_password">Current password</label
                        ><input
                            id="current_password"
                            v-model="password.current_password"
                            type="password"
                            required
                            autocomplete="current-password"
                        />
                    </div>
                    <div class="field">
                        <label for="password">New password</label
                        ><input
                            id="password"
                            v-model="password.password"
                            type="password"
                            required
                            minlength="12"
                            autocomplete="new-password"
                        />
                    </div>
                    <div class="field">
                        <label for="password_confirmation"
                            >Confirm new password</label
                        ><input
                            id="password_confirmation"
                            v-model="password.password_confirmation"
                            type="password"
                            required
                            minlength="12"
                            autocomplete="new-password"
                        />
                    </div>
                </div>
                <div class="form-actions">
                    <span v-if="password.recentlySuccessful" class="muted"
                        >Password updated</span
                    ><button
                        class="btn primary"
                        :disabled="password.processing"
                    >
                        Update password
                    </button>
                </div>
            </form>
        </section></HubLayout
    >
</template>

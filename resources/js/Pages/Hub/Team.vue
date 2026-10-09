<script setup lang="ts">
import { Head, useForm, router } from "@inertiajs/vue3";
import HubLayout from "@/Layouts/HubLayout.vue";
import HubErrors from "@/Components/HubErrors.vue";
import type { Member } from "@/hub-types";
defineProps<{ members: Member[] }>();
const form = useForm({ name: "", email: "", password: "", role: "developer" });
function roleChange(member: Member, event: Event) {
    router.patch(`/team/${member.id}`, {
        role: (event.target as HTMLSelectElement).value,
        active: member.pivot.active,
    });
}
function activeChange(member: Member) {
    router.patch(`/team/${member.id}`, {
        role: member.pivot.role,
        active: !member.pivot.active,
    });
}
</script>
<template>
    <Head title="Team & access" /><HubLayout
        title="Team & access"
        subtitle="Workspace roles establish boundaries. Each environment needs explicit access."
        ><section class="card">
            <div class="card-heading"><h2>Workspace members</h2></div>
            <div class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Role</th>
                            <th>MFA</th>
                            <th>Membership</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="member in members" :key="member.id">
                            <td>
                                <strong>{{ member.name }}</strong>
                                <p>{{ member.email }}</p>
                            </td>
                            <td>
                                <span v-if="member.pivot.role === 'owner'"
                                    >Owner</span
                                ><select
                                    v-else
                                    class="search"
                                    :value="member.pivot.role"
                                    aria-label="Member role"
                                    @change="roleChange(member, $event)"
                                >
                                    <option>admin</option>
                                    <option>developer</option>
                                    <option>viewer</option>
                                </select>
                            </td>
                            <td>
                                {{
                                    member.two_factor_confirmed_at
                                        ? "Enabled"
                                        : "Not configured"
                                }}
                            </td>
                            <td>
                                <button
                                    v-if="member.pivot.role !== 'owner'"
                                    class="btn small"
                                    @click="activeChange(member)"
                                >
                                    {{
                                        member.pivot.active
                                            ? "Revoke membership"
                                            : "Restore membership"
                                    }}</button
                                ><span v-else>Active</span>
                                <p v-if="!member.pivot.active">Inactive</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card">
            <div class="card-heading"><h2>Create member</h2></div>
            <form
                class="card-content"
                @submit.prevent="
                    form.post('/team', {
                        onFinish: () => form.reset('password'),
                    })
                "
            >
                <HubErrors :errors="form.errors" />
                <div class="form-grid">
                    <div class="field">
                        <label for="name">Full name</label
                        ><input id="name" v-model="form.name" required />
                    </div>
                    <div class="field">
                        <label for="email">Email</label
                        ><input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                        />
                    </div>
                    <div class="field">
                        <label for="password">Initial password</label
                        ><input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            minlength="12"
                            autocomplete="new-password"
                        /><small
                            >At least 12 characters, with letters and numbers.
                            Share securely and change after login.</small
                        >
                    </div>
                    <div class="field">
                        <label for="role">Workspace role</label
                        ><select id="role" v-model="form.role">
                            <option>developer</option>
                            <option>viewer</option>
                            <option>admin</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn primary" :disabled="form.processing">
                        Create member
                    </button>
                </div>
            </form>
        </section></HubLayout
    >
</template>

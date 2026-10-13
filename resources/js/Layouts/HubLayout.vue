<script setup lang="ts">
import { computed, ref, onUnmounted } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import "../../css/hub.css";
defineProps<{ title: string; subtitle?: string }>();
const page = usePage<any>();
const admin = computed(() =>
    ["owner", "admin"].includes(page.props.auth?.role),
);
const open = ref(false);
const mobileQuery = window.matchMedia("(max-width: 760px)");
const mobile = ref(mobileQuery.matches);
const resize = () => {
    mobile.value = mobileQuery.matches;
    if (!mobile.value) open.value = false;
};
mobileQuery.addEventListener("change", resize);
onUnmounted(() => mobileQuery.removeEventListener("change", resize));
const theme = ref(localStorage.getItem("deployhub-theme") || "dark");
document.documentElement.dataset.theme = theme.value;
function toggleTheme() {
    theme.value = theme.value === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = theme.value;
    localStorage.setItem("deployhub-theme", theme.value);
}
const navGroups = computed(() => [
    {
        name: "Deployments",
        items: [
            { name: "Overview", url: "/dashboard", icon: "◈" },
            { name: "Projects", url: "/projects", icon: "▤" },
            { name: "Deployment activity", url: "/activity", icon: "↗" },
            { name: "Approvals", url: "/approvals", icon: "✓" },
        ],
    },
    {
        name: "Manage",
        items: [
            ...(admin.value
                ? [{ name: "Servers", url: "/servers", icon: "▥" }]
                : []),
            ...(admin.value
                ? [
                      { name: "Team & access", url: "/team", icon: "♙" },
                      { name: "Audit log", url: "/audit-logs", icon: "≡" },
                  ]
                : []),
            { name: "Security & profile", url: "/security", icon: "⚙" },
        ],
    },
]);
</script>
<template>
    <div class="hub-shell" @keydown.esc="open = false">
        <a href="#main" class="skip-link">Skip to content</a>
        <button
            v-if="mobile && open"
            class="sidebar-scrim"
            aria-label="Dismiss navigation"
            @click="open = false"
        ></button>
        <aside
            id="workspace-navigation"
            class="hub-sidebar"
            :class="{ open }"
            :inert="mobile && !open"
            :aria-hidden="mobile && !open"
        >
            <button
                v-if="mobile && open"
                class="icon-button sidebar-close"
                aria-label="Close navigation"
                @click="open = false"
            >
                ✕
            </button>
            <Link href="/dashboard" class="brand"
                ><span class="brand-mark">D</span>DeployHub<span
                    class="brand-dot"
                    >.</span
                ></Link
            >
            <div class="workspace-box">
                <span class="workspace-avatar">{{
                    page.props.auth?.workspace?.name?.charAt(0) || "W"
                }}</span>
                <div>
                    <strong>{{ page.props.auth?.workspace?.name }}</strong
                    ><small>Private workspace</small>
                </div>
            </div>
            <nav aria-label="Main navigation">
                <template v-for="group in navGroups" :key="group.name">
                    <div class="nav-caption">{{ group.name }}</div>
                    <Link
                        v-for="item in group.items"
                        :key="item.url"
                        :href="item.url"
                        :class="[
                            'nav-link',
                            { active: page.url.split('?')[0] === item.url },
                        ]"
                        @click="open = false"
                        ><span class="nav-icon" aria-hidden="true">{{
                            item.icon
                        }}</span
                        >{{ item.name }}</Link
                    >
                </template>
            </nav>
            <div class="sidebar-note">
                <span class="mini-dot"></span>Private deployment workspace
            </div>
            <div class="profile-box">
                <span class="profile-avatar">{{
                    page.props.auth?.user?.name?.charAt(0)
                }}</span>
                <div>
                    <strong>{{ page.props.auth?.user?.name }}</strong
                    ><small>{{ page.props.auth?.role }}</small>
                </div>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="icon-button"
                    aria-label="Sign out"
                    title="Sign out"
                    >↪</Link
                >
            </div>
        </aside>
        <div class="hub-body">
            <header class="topbar">
                <button
                    class="icon-button mobile-menu"
                    @click="open = !open"
                    aria-label="Toggle navigation"
                >
                    ☰</button
                ><span class="breadcrumb"
                    >Workspace <span>/</span> {{ title }}</span
                >
                <div class="topbar-actions">
                    <span class="private-label">◇ Private access</span
                    ><button
                        class="icon-button"
                        @click="toggleTheme"
                        :aria-label="
                            theme === 'dark'
                                ? 'Use light theme'
                                : 'Use dark theme'
                        "
                    >
                        {{ theme === "dark" ? "☀" : "☾" }}
                    </button>
                </div>
            </header>
            <main id="main" class="hub-main" tabindex="-1">
                <div class="page-heading">
                    <div>
                        <h1>{{ title }}</h1>
                        <p v-if="subtitle" class="subtitle">{{ subtitle }}</p>
                    </div>
                    <div class="heading-actions"><slot name="actions" /></div>
                </div>
                <div
                    v-if="page.props.flash?.message"
                    class="notice success"
                    role="status"
                >
                    {{ page.props.flash.message }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="notice error"
                    role="alert"
                >
                    {{ page.props.flash.error }}
                </div>
                <slot />
            </main>
            <footer class="hub-footer">
                <span>DeployHub</span>
            </footer>
        </div>
    </div>
</template>

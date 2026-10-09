<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import "../../css/hub.css";
</script>
<template>
    <div class="auth-page">
        <div class="auth-card">
            <Link href="/login" class="brand"
                ><span class="brand-mark">D</span>DeployHub<span
                    class="brand-dot"
                    >.</span
                ></Link
            >
            <div class="card legacy-auth"><slot /></div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import QRCode from "qrcode";

const props = defineProps<{ secret: string; account: string }>();
const symbol = computed(() => {
    const issuer = "DeployHub";
    const parameters = new URLSearchParams({
        secret: props.secret,
        issuer,
        algorithm: "SHA1",
        digits: "6",
        period: "30",
    });
    const uri = `otpauth://totp/${encodeURIComponent(issuer)}:${encodeURIComponent(props.account)}?${parameters}`;

    try {
        const { modules } = QRCode.create(uri, { errorCorrectionLevel: "M" });
        const quietZone = 4;
        const paths: string[] = [];
        for (let row = 0; row < modules.size; row++) {
            for (let column = 0; column < modules.size; column++) {
                if (modules.data[row * modules.size + column]) {
                    paths.push(
                        `M${column + quietZone},${row + quietZone}h1v1h-1z`,
                    );
                }
            }
        }
        return {
            size: modules.size + quietZone * 2,
            path: paths.join(""),
        };
    } catch {
        return null;
    }
});
</script>

<template>
    <svg
        v-if="symbol"
        :viewBox="`0 0 ${symbol.size} ${symbol.size}`"
        class="authenticator-qr"
        role="img"
        :aria-label="`Scan to add DeployHub (${account}) to your authenticator`"
        shape-rendering="crispEdges"
    >
        <rect width="100%" height="100%" fill="#fff" />
        <path :d="symbol.path" fill="#000" />
    </svg>
    <p v-else class="notice warning" role="status">
        The QR code could not be generated. Use the manual setup key below.
    </p>
</template>

<style scoped>
.authenticator-qr {
    display: block;
    width: 256px;
    max-width: 100%;
    height: auto;
    flex-shrink: 0;
}
</style>

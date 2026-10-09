import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import { test } from "node:test";
import { parse, compileScript } from "@vue/compiler-sfc";
import { renderToString } from "@vue/server-renderer";
import { h } from "vue";
import ts from "typescript";
import jsQR from "jsqr";

const source = await readFile(
    new URL(
        "../../resources/js/Components/AuthenticatorQrCode.vue",
        import.meta.url,
    ),
    "utf8",
);
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, {
    id: "authenticator-qr-test",
    inlineTemplate: true,
});
const { outputText } = ts.transpileModule(compiled.content, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
        esModuleInterop: true,
    },
});
const componentModule = { exports: {} };
new Function("require", "module", "exports", outputText)(
    createRequire(import.meta.url),
    componentModule,
    componentModule.exports,
);
const AuthenticatorQrCode = componentModule.exports.default;

async function decodeRenderedCode(secret, account) {
    const markup = await renderToString(
        h(AuthenticatorQrCode, { secret, account }),
    );
    const size = Number(markup.match(/viewBox="0 0 (\d+) \d+"/)?.[1]);
    assert.ok(size > 8, "The QR code includes a four-module quiet zone");
    const scale = 6;
    const width = size * scale;
    const pixels = new Uint8ClampedArray(width * width * 4).fill(255);
    const path = markup.match(/<path[^>]* d="([^"]+)"/)?.[1];
    assert.ok(path, "The component renders a QR symbol");
    for (const [, column, row] of path.matchAll(/M(\d+),(\d+)h1v1h-1z/g)) {
        for (let y = Number(row) * scale; y < (Number(row) + 1) * scale; y++) {
            for (
                let x = Number(column) * scale;
                x < (Number(column) + 1) * scale;
                x++
            ) {
                const offset = (y * width + x) * 4;
                pixels[offset] = pixels[offset + 1] = pixels[offset + 2] = 0;
            }
        }
    }
    const decoded = jsQR(pixels, width, width);
    assert.ok(decoded, "An independent scanner can decode the rendered SVG");
    return new URL(decoded.data);
}

test("the enrollment QR is readable and carries the pending TOTP settings", async () => {
    const secret = "JBSWY3DPEHPK3PXP";
    const account = "qr-test@example.test";
    const uri = await decodeRenderedCode(secret, account);
    assert.equal(uri.protocol, "otpauth:");
    assert.equal(uri.hostname, "totp");
    assert.equal(decodeURIComponent(uri.pathname), `/DeployHub:${account}`);
    assert.equal(uri.searchParams.get("secret"), secret);
    assert.equal(uri.searchParams.get("issuer"), "DeployHub");
    assert.equal(uri.searchParams.get("algorithm"), "SHA1");
    assert.equal(uri.searchParams.get("digits"), "6");
    assert.equal(uri.searchParams.get("period"), "30");
});

test("account punctuation and Unicode cannot change the QR settings", async () => {
    const account = "qr+测试&issuer=Other@example.test";
    const uri = await decodeRenderedCode(
        "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ",
        account,
    );
    assert.equal(decodeURIComponent(uri.pathname), `/DeployHub:${account}`);
    assert.equal(uri.searchParams.get("issuer"), "DeployHub");
    assert.equal(uri.searchParams.getAll("issuer").length, 1);
});

import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import { test } from "node:test";
import { parse, compileScript } from "@vue/compiler-sfc";
import { createRenderer, h, nextTick } from "vue";
import ts from "typescript";

const require = createRequire(import.meta.url);
const source = await readFile(
    new URL("../../resources/js/Pages/Hub/Deployment.vue", import.meta.url),
    "utf8",
);
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, {
    id: "deployment-polling-test",
    inlineTemplate: true,
});
function transpile(source) {
    return ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
            esModuleInterop: true,
        },
    }).outputText;
}
const typesModule = { exports: {} };
new Function(
    "exports",
    transpile(
        await readFile(
            new URL("../../resources/js/hub-types.ts", import.meta.url),
            "utf8",
        ),
    ),
)(typesModule.exports);

const node = (text = "") => ({
    text,
    children: [],
    parent: null,
    addEventListener() {},
});
const renderer = createRenderer({
    createElement: () => node(),
    createText: node,
    createComment: () => node(),
    setText: (element, text) => {
        element.text = text;
    },
    setElementText: (element, text) => {
        element.text = text;
        element.children = [];
    },
    patchProp: () => {},
    parentNode: (element) => element.parent,
    nextSibling: (element) =>
        element.parent?.children[
            element.parent.children.indexOf(element) + 1
        ] || null,
    insert: (element, parent, anchor) => {
        if (element.parent)
            element.parent.children.splice(
                element.parent.children.indexOf(element),
                1,
            );
        element.parent = parent;
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        parent.children.splice(
            index < 0 ? parent.children.length : index,
            0,
            element,
        );
    },
    remove: (element) => {
        element.parent?.children.splice(
            element.parent.children.indexOf(element),
            1,
        );
        element.parent = null;
    },
});
const text = (element) =>
    [element.text, ...element.children.map(text)].join(" ");

function mount(responses) {
    const timers = new Map();
    const intervals = new Set();
    const requests = [];
    const errors = [];
    let timerId = 0;
    const module = { exports: {} };
    const stub = { render: () => null };
    const imports = (name) => {
        if (name === "@/hub-types") return typesModule.exports;
        if (name === "axios")
            return {
                get: async (url) => {
                    requests.push(url);
                    const response = responses.shift();
                    if (response instanceof Error) throw response;
                    return { data: response };
                },
            };
        if (name === "@inertiajs/vue3")
            return {
                Head: stub,
                Link: {
                    setup:
                        (_, { slots }) =>
                        () =>
                            h("a", slots.default?.()),
                },
                useForm: (data) => ({
                    ...data,
                    errors: {},
                    processing: false,
                    clearErrors() {},
                }),
            };
        if (name === "@/Layouts/HubLayout.vue")
            return {
                setup:
                    (_, { slots }) =>
                    () =>
                        h("main", [slots.actions?.(), slots.default?.()]),
            };
        if (name === "@/Components/HubStatus.vue")
            return {
                props: ["state"],
                setup: (props) => () => h("span", props.state),
            };
        if (name === "@/Components/HubErrors.vue") return stub;
        return require(name);
    };
    new Function(
        "require",
        "module",
        "exports",
        "setTimeout",
        "clearTimeout",
        "setInterval",
        "clearInterval",
        transpile(compiled.content),
    )(
        imports,
        module,
        module.exports,
        (callback) => {
            const id = ++timerId;
            timers.set(id, callback);
            return id;
        },
        (id) => timers.delete(id),
        () => {
            const id = ++timerId;
            intervals.add(id);
            return id;
        },
        (id) => intervals.delete(id),
    );
    const deployment = {
        id: 112,
        environment_id: 1,
        state: "queued",
        digest: "a".repeat(64),
        target_sha: "b".repeat(40),
        expires_at: "2099-01-01T00:00:00Z",
        created_at: "2026-10-12T00:00:00Z",
        preflight: { commits: [] },
        candidate: { pipeline: [], branch: "main", pipeline_version: 1 },
        environment: {
            name: "Staging",
            project: { name: "Fixture app" },
            server: { name: "Fixture SSH" },
        },
        user: { name: "Owner" },
        steps: [],
    };
    const root = node();
    const app = renderer.createApp(module.exports.default, {
        deployment,
        canConfirm: true,
        canApprove: false,
        canRecover: true,
        canReconcile: true,
        workerOnline: true,
    });
    app.config.errorHandler = (error) => errors.push(error);
    app.mount(root);
    return {
        root,
        requests,
        errors,
        timers,
        intervals,
        async poll() {
            assert.equal(timers.size, 1, "Exactly one poll is scheduled");
            const [id, callback] = timers.entries().next().value;
            timers.delete(id);
            await callback();
            await nextTick();
        },
        stop() {
            app.unmount();
        },
    };
}

for (const state of ["succeeded", "failed", "unknown"]) {
    test(`polling from queued through running to ${state} keeps the page visible`, async () => {
        const view = mount([
            { state: "running", environment: { name: "Staging" }, steps: [] },
            {
                state,
                code_updated: true,
                finished_at: "2026-10-12T00:01:00Z",
                steps: [],
            },
        ]);
        try {
            await view.poll();
            assert.match(text(view.root), /Deployment progress/);
            await view.poll();
            assert.match(text(view.root), new RegExp(state));
            if (state === "failed")
                assert.match(text(view.root), /Prepare recovery preview/);
            if (state === "unknown")
                assert.match(text(view.root), /Administrator reconciliation/);
            assert.deepEqual(
                view.errors,
                [],
                "No render error when a response lacks server metadata",
            );
            assert.equal(view.timers.size, 0, "Terminal states stop polling");
            assert.deepEqual(
                view.requests,
                Array(2).fill("/deployments/112/events"),
            );
        } finally {
            view.stop();
        }
        assert.equal(
            view.intervals.size,
            0,
            "Unmount clears the elapsed-time timer",
        );
    });
}

test("a poll failure displays an error, retries, and cleans up on navigation", async () => {
    const view = mount([new Error("Offline"), { state: "running", steps: [] }]);
    try {
        await view.poll();
        assert.match(text(view.root), /Status refresh failed/);
        await view.poll();
        assert.doesNotMatch(text(view.root), /Status refresh failed/);
        assert.match(text(view.root), /Fixture SSH/);
        assert.deepEqual(view.errors, []);
    } finally {
        view.stop();
    }
    assert.equal(view.timers.size, 0);
    assert.equal(view.intervals.size, 0);
});

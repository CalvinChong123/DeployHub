export type AutomationTarget = {
    id: number;
    name: string;
    project: string;
    branch: string;
    requires_approval: boolean;
};
export type AutomationGrantSummary = {
    id: number;
    name: string;
    target: string;
    expires_at: string;
    revoked_at: string | null;
    expired: boolean;
};
export type Server = {
    id: number;
    name: string;
    host?: string;
    port?: number;
    username?: string;
    fingerprint?: string;
    status: string;
    checked_at?: string;
    error?: string;
};
export type Paginated<T> = {
    data: T[];
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
export type Commit = {
    sha: string;
    subject: string;
    author: string;
    date: string;
};
export type Preflight = {
    duration_ms?: number;
    clean?: boolean;
    changes?: string;
    worktree_digest?: string;
    error?: string;
    head?: string;
    target?: string;
    branch?: string;
    count?: number;
    commits?: Commit[];
    fetched_at?: string;
};
export type DeploymentHook = {
    type?: string;
    id: string;
    name: string;
    phase: "pre" | "post";
    script: string;
    enabled: boolean;
};
export type Environment = {
    latest_deployment?: DeploymentSummary | null;
    last_successful_deployment?: DeploymentSummary | null;
    deleted_at?: string | null;
    id: number;
    name: string;
    project_id: number;
    server_id: number;
    path: string;
    branch: string;
    production: boolean;
    requires_approval: boolean;
    pipeline: string[];
    hooks?: DeploymentHook[] | null;
    pipeline_version: number;
    health_url?: string;
    status: string;
    server: Server;
    project?: Project;
    last_preflight?: Preflight;
    checked_at?: string;
    can_deploy?: boolean;
};
export type Project = {
    deleted_at?: string | null;
    id: number;
    name: string;
    repository: string;
    description?: string;
    environments: Environment[];
};
export type Member = {
    id: number;
    name: string;
    email: string;
    two_factor_confirmed_at?: string;
    pivot: { role: string; active: boolean };
};
export type Permission = {
    user_id: number;
    can_view: boolean;
    can_deploy: boolean;
    can_approve: boolean;
};
export type DeploymentSummary = {
    id: number;
    state: string;
    target_sha: string;
    created_at: string;
    started_at?: string | null;
    finished_at?: string | null;
    scheduled_for?: string | null;
    code_updated?: boolean;
    user?: { name: string };
};
export type Deployment = {
    id: number;
    environment_id: number;
    user_id: number;
    state: string;
    scheduled_for?: string | null;
    schedule_timezone?: string | null;
    starting_sha: string;
    target_sha: string;
    digest: string;
    preflight: Preflight;
    candidate: {
        pipeline: string[];
        hooks?: DeploymentHook[];
        pipeline_version: number;
        path: string;
        branch: string;
        production: boolean;
        requires_approval: boolean;
        recovery_of?: number;
        scheduled_for?: string;
        schedule_timezone?: string;
    };
    environment?: Environment;
    user?: { name: string };
    expires_at: string;
    created_at: string;
    started_at?: string;
    finished_at?: string;
    error?: string;
    code_updated: boolean;
    cancel_requested: boolean;
    approval?: { outcome: string; reason?: string; user_id: number };
    steps: {
        id: number;
        type: string;
        state: string;
        exit_code?: number;
        output?: string;
        started_at?: string;
        finished_at?: string;
    }[];
};
export const date = (value?: string | null, timezone?: string | null) =>
    value
        ? new Date(value).toLocaleString(
              undefined,
              timezone ? { timeZone: timezone } : undefined,
          )
        : "Never";
export const short = (sha?: string) => sha?.slice(0, 8) || "Unknown";
export const duration = (
    start?: string | null,
    finish?: string | null,
    clock = Date.now(),
) => {
    if (!start) return "Not started";
    const seconds = Math.max(
        0,
        Math.floor(
            ((finish ? new Date(finish).getTime() : clock) -
                new Date(start).getTime()) /
                1000,
        ),
    );
    return seconds < 60
        ? `${seconds}s`
        : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
};
export const inspectionLabel = (state: string) =>
    (
        ({
            ready: "Check passed",
            local_changes: "Local changes",
            blocked: "Check failed",
            unknown: "Not checked",
        }) as Record<string, string>
    )[state] || state.replace(/_/g, " ");
export const stepLabel: Record<string, string> = {
    repository_ready: "Recheck repository after Pre hooks",
    preflight: "Revalidate exact candidate",
    fast_forward: "Fast-forward Git update",
    composer: "Install Composer dependencies",
    cache_clear: "Clear Laravel caches",
    optimize: "Optimize Laravel",
    queue_restart: "Restart Laravel queues",
    health: "HTTPS health check",
    verify_head: "Verify deployed commit",
};

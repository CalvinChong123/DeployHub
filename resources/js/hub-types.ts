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
    error?: string;
    head?: string;
    target?: string;
    branch?: string;
    count?: number;
    commits?: Commit[];
    fetched_at?: string;
};
export type Environment = {
    id: number;
    name: string;
    project_id: number;
    server_id: number;
    path: string;
    branch: string;
    production: boolean;
    requires_approval: boolean;
    pipeline: string[];
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
export type Deployment = {
    id: number;
    environment_id: number;
    user_id: number;
    state: string;
    starting_sha: string;
    target_sha: string;
    digest: string;
    preflight: Preflight;
    candidate: {
        pipeline: string[];
        pipeline_version: number;
        path: string;
        branch: string;
        production: boolean;
        requires_approval: boolean;
        recovery_of?: number;
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
export const date = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : "Never";
export const short = (sha?: string) => sha?.slice(0, 8) || "Unknown";
export const stepLabel: Record<string, string> = {
    preflight: "Revalidate exact candidate",
    fast_forward: "Fast-forward Git update",
    composer: "Install Composer dependencies",
    cache_clear: "Clear Laravel caches",
    optimize: "Optimize Laravel",
    queue_restart: "Restart Laravel queues",
    health: "HTTPS health check",
    verify_head: "Verify deployed commit",
};

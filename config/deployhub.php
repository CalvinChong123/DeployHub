<?php

return [
    'candidate_minutes' => 60,
    'preflight_reuse_seconds' => 120,
    'command_timeout' => 120,
    'deployment_timeout' => 900,
    'lock_seconds' => 1200,
    'log_bytes' => 32768,
    'require_mfa' => env('DEPLOYHUB_REQUIRE_MFA', true),
    'log_retention_days' => (int) env('DEPLOYHUB_LOG_RETENTION_DAYS', 30),
];

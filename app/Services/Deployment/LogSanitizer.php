<?php

namespace App\Services\Deployment;

class LogSanitizer
{
    public function clean(string $value, array $knownSecrets = []): string
    {
        foreach ($knownSecrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $value = str_replace($secret, '[REDACTED]', $value);
            }
        }
        $value = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----.*?(?:-----END [^-]*PRIVATE KEY-----|$)/s', '[REDACTED KEY]', $value);
        $value = preg_replace('~(https?://)[^\s/@]+(?::[^\s/@]*)?@~i', '$1[REDACTED]@', $value);
        $value = preg_replace('/\b(?:gh[pousr]_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+|glpat-[A-Za-z0-9_-]+)\b/', '[REDACTED TOKEN]', $value);
        $value = preg_replace('/\b(?:[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|PRIVATE_KEY|API_KEY|APP_KEY)[A-Z0-9_]*|Authorization)\s*[:=]\s*[^\r\n]*/i', '[REDACTED SECRET]', $value);
        $value = preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|$))/', '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return mb_strcut($value, 0, config('deployhub.log_bytes'), 'UTF-8');
    }
}

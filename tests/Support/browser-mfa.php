<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Local fixture only.');
}
$user = User::where('email', 'ui-review@example.test')->firstOrFail();
if (! $user->workspaces()->where('slug', 'browser-ui-fixture')->exists()) {
    throw new RuntimeException('Disposable account required.');
}
$totp = new Google2FA;
$secret = $user->two_factor_secret ?: $totp->generateSecretKey();
$user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
echo $totp->getCurrentOtp($secret)."\n";

<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Server;
use App\Services\Audit;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\DeploymentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use phpseclib3\Crypt\PublicKeyLoader;

class ServerController extends Controller
{
    private function scoped(Request $request, Server $server): void
    {
        abort_unless($server->workspace_id === $request->user()->workspace()->id, 404);
    }

    public function index(Request $request)
    {
        return Inertia::render('Hub/Servers', ['servers' => Server::where('workspace_id', $request->user()->workspace()->id)->orderBy('name')->get()]);
    }

    private function credentials(Request $request): array
    {
        $data = $request->validate(['private_key' => 'required|string|max:32768', 'passphrase' => 'nullable|string|max:1024']);
        try {
            PublicKeyLoader::loadPrivateKey($data['private_key'], $data['passphrase'] ?? false);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['private_key' => 'Cannot load this private key with the supplied passphrase.']);
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/D'], 'port' => 'required|integer|min:1|max:65535', 'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D']]);
        $credentials = $this->credentials($request);
        DB::transaction(function () use ($request, $data, $credentials) {
            $server = Server::create([...$data, 'workspace_id' => $request->user()->workspace()->id]);
            $server->credential()->create($credentials);
            Audit::record($server->workspace_id, $request->user()->id, 'server.created', 'server:'.$server->id);
        });

        return back()->with('message', 'Server registered. Discover and trust its fingerprint before testing authentication.');
    }

    public function rotate(Request $request, Server $server)
    {
        $this->scoped($request, $server);
        $credentials = $this->credentials($request);
        DB::transaction(function () use ($server, $request, $credentials) {
            $server->credential()->updateOrCreate(['server_id' => $server->id], $credentials);
            $server->increment('credential_version');
            $server->update(['status' => 'unknown', 'checked_at' => null, 'error' => null]);
            Environment::where('server_id', $server->id)->update(['status' => 'unknown', 'last_preflight' => null, 'checked_at' => null]);
            Audit::record($server->workspace_id, $request->user()->id, 'server.credential_rotated', 'server:'.$server->id);
        });

        return back()->with('message', 'Credential rotated. Existing deployment previews are invalidated.');
    }

    public function update(Request $request, Server $server)
    {
        $this->scoped($request, $server);
        $data = $request->validate(['name' => 'required|string|max:80', 'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/D'], 'port' => 'required|integer|min:1|max:65535', 'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D']]);
        $peerChanged = $server->host !== $data['host'] || $server->port !== (int) $data['port'] || $server->username !== $data['username'];
        DB::transaction(function () use ($request, $server, $data, $peerChanged) {
            $server->update([...$data, ...($peerChanged ? ['fingerprint' => null, 'status' => 'unverified', 'checked_at' => null, 'error' => null, 'credential_version' => $server->credential_version + 1] : [])]);
            if ($peerChanged) {
                Environment::where('server_id', $server->id)->update(['status' => 'unknown', 'last_preflight' => null, 'checked_at' => null]);
            }
            Audit::record($server->workspace_id, $request->user()->id, 'server.connection_changed', 'server:'.$server->id, ['peer_changed' => $peerChanged]);
        });

        return back()->with('message', $peerChanged ? 'Connection changed. Verify the new host fingerprint before authentication.' : 'Server label saved.');
    }

    public function discover(Request $request, Server $server, ConnectionFactory $connections)
    {
        $this->scoped($request, $server);
        $fingerprint = $connections->discover($server);
        $request->session()->put('hostkey.'.$server->id, [
            'fingerprint' => $fingerprint,
            'expires' => now()->addMinutes(10)->timestamp,
            'server_identity' => $server->only(['host', 'port', 'username', 'credential_version', 'fingerprint']),
        ]);

        return back()->with('fingerprint', ['server_id' => $server->id, 'value' => $fingerprint]);
    }

    public function verify(Request $request, Server $server, ConnectionFactory $connections)
    {
        $this->scoped($request, $server);
        $data = $request->validate(['fingerprint' => 'required|string|max:100', 'trust_method' => 'sometimes|required|in:first_use,independent']);
        $method = $data['trust_method'] ?? 'independent';
        $request->validate([$method === 'first_use' ? 'trust_first_use' : 'verified_out_of_band' => 'accepted']);
        $challenge = $request->session()->get('hostkey.'.$server->id);
        if (! $challenge || $challenge['expires'] < now()->timestamp || ($challenge['server_identity'] ?? null) !== $server->only(['host', 'port', 'username', 'credential_version', 'fingerprint']) || ! hash_equals($challenge['fingerprint'], $data['fingerprint'])) {
            throw new DeploymentException('Fingerprint discovery expired or server configuration changed. Discover it again.');
        }
        if ($method === 'first_use' && $server->fingerprint) {
            throw ValidationException::withMessages(['trust_method' => 'A fingerprint is already saved. Independently verify it before replacing it.']);
        }
        if (! hash_equals($data['fingerprint'], $connections->discover($server))) {
            throw new DeploymentException('SSH host key changed after discovery. Discover it again before trusting it.');
        }
        DB::transaction(function () use ($request, $server, $data, $method, $challenge) {
            $current = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            if ($challenge['expires'] < now()->timestamp || $challenge['server_identity'] !== $current->only(['host', 'port', 'username', 'credential_version', 'fingerprint'])) {
                throw new DeploymentException('Server configuration or saved fingerprint changed. Discover it again.');
            }
            $current->update(['fingerprint' => $data['fingerprint'], 'status' => 'unknown', 'checked_at' => null, 'error' => null]);
            Environment::where('server_id', $current->id)->update(['status' => 'unknown', 'last_preflight' => null, 'checked_at' => null]);
            Audit::record($current->workspace_id, $request->user()->id, $method === 'first_use' ? 'server.hostkey_trusted_first_use' : 'server.hostkey_verified', 'server:'.$current->id, ['fingerprint' => $data['fingerprint'], 'trust_method' => $method, 'previous_fingerprint' => $challenge['server_identity']['fingerprint']]);
        });
        $request->session()->forget('hostkey.'.$server->id);

        return back()->with('message', $method === 'first_use' ? 'First-use host fingerprint trusted and saved. Test authentication next.' : 'Independently verified host fingerprint pinned. Test authentication next.');
    }

    public function test(Request $request, Server $server, ConnectionFactory $connections)
    {
        $this->scoped($request, $server);
        try {
            $result = $connections->connect($server)->run("'true'");
            if ($result->exitCode !== 0) {
                throw new DeploymentException('SSH authenticated but shell access test failed.');
            }
            $server->update(['status' => 'verified', 'checked_at' => now(), 'error' => null]);
            Audit::record($server->workspace_id, $request->user()->id, 'server.connection_tested', 'server:'.$server->id, ['outcome' => 'verified']);

            return back()->with('message', 'SSH authentication and shell access verified. Repository readiness is checked separately.');
        } catch (DeploymentException $e) {
            $server->update(['status' => 'failed', 'checked_at' => now(), 'error' => $e->getMessage()]);
            Audit::record($server->workspace_id, $request->user()->id, 'server.connection_tested', 'server:'.$server->id, ['outcome' => 'failed']);
            throw $e;
        }
    }

    public function destroy(Request $request, Server $server)
    {
        $this->scoped($request, $server);
        abort_if(Environment::withTrashed()->where('server_id', $server->id)->exists(), 422, 'This server has retained project or deployment records.');
        DB::transaction(function () use ($request, $server) {
            Audit::record($server->workspace_id, $request->user()->id, 'server.deleted', 'server:'.$server->id);
            $server->delete();
        });

        return back()->with('message', 'Server and encrypted credentials deleted.');
    }
}

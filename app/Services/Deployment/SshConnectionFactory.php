<?php

namespace App\Services\Deployment;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SshConnectionFactory implements ConnectionFactory
{
    public function __construct(private HostKeyVerifier $verifier) {}

    public function discover(Server $server): string
    {
        try {
            $ssh = new SSH2($server->host, $server->port, 10);
            $key = $ssh->getServerPublicHostKey();
            if (! is_string($key)) {
                throw new DeploymentException('Could not obtain the SSH host key.');
            }

            return $this->verifier->fingerprint($key);
        } catch (DeploymentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new DeploymentException('SSH host is unreachable or did not complete the handshake.');
        } finally {
            if (isset($ssh)) {
                $ssh->disconnect();
            }
        }
    }

    public function connect(Server $server): RemoteConnection
    {
        try {
            $ssh = new SSH2($server->host, $server->port, 10);
            $key = $ssh->getServerPublicHostKey();
            if (! is_string($key)) {
                throw new DeploymentException('Could not obtain the SSH host key.');
            }
            // Verify the peer BEFORE decrypting credentials or attempting authentication.
            $this->verifier->verify($server->fingerprint, $this->verifier->fingerprint($key));
            $credential = $server->credential;
            if (! $credential) {
                throw new DeploymentException('SSH credentials are missing.');
            }
            $privateKey = PublicKeyLoader::loadPrivateKey($credential->private_key, $credential->passphrase ?: false);
            if (! $ssh->login($server->username, $privateKey)) {
                throw new DeploymentException('SSH authentication failed. Check the deployment account and key.');
            }
            $ssh->setTimeout(config('deployhub.command_timeout'));

            return new SshRemoteConnection($ssh);
        } catch (DeploymentException $e) {
            if (isset($ssh)) {
                $ssh->disconnect();
            } throw $e;
        } catch (\Throwable) {
            if (isset($ssh)) {
                $ssh->disconnect();
            } throw new DeploymentException('SSH connection or key loading failed. Check connectivity and credentials.');
        }
    }
}

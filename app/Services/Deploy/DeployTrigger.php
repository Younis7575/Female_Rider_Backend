<?php

namespace App\Services\Deploy;

/**
 * Starts deploy/ionos/pull-deploy.sh in the background (shared hosting). The script only
 * installs a newer commit of the "production" branch, which only CI can push, so the
 * trigger itself needs no secret: the worst a caller can cause is an extra `git fetch`.
 */
class DeployTrigger
{
    public function script(): string
    {
        return base_path('deploy/ionos/pull-deploy.sh');
    }

    public function launch(): bool
    {
        $script = $this->script();
        if (! is_file($script) || ! function_exists('exec')) {
            return false;
        }
        $env = sprintf('APP_DIR=%s HOME=%s', escapeshellarg(base_path()), escapeshellarg(dirname(base_path())));
        exec(sprintf('%s nohup bash %s > /dev/null 2>&1 &', $env, escapeshellarg($script)));

        return true;
    }
}

<?php

namespace Violinist\NeedsUpdateCheckRunner;

use Codeaken\SshKey\SshKey;
use Codeaken\SshKey\SshKeyPair;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Process\Process;
use Violinist\ProviderFactory\Provider\ProviderInterface;
use Violinist\ProviderFactory\ProviderFactory;
use Violinist\Slug\Slug;
use Violinist\UpdateCheckData\UpdateCheckData;
use Violinist\UpdateCheckData\UpdateCheckSha;
use Wa72\SimpleLogger\ArrayLogger;

class Runner
{
    use LoggerAwareTrait;

    private $repoUrl;

    private $token;

    private $directory;

    /**
     * @var SshKeyPair
     */
    private $userKey;

    /**
     * @var SshKeyPair
     */
    private $projectKey;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @param mixed $repoUrl
     */
    public function setRepoUrl($repoUrl)
    {
        $this->repoUrl = $repoUrl;
    }

    public function setToken($token)
    {
        $this->token = $token;
    }

    public function __construct()
    {
    }

    public function setCache(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    public function getMessages()
    {
        if (!$this->logger instanceof ArrayLogger) {
            return [];
        }
        /** @var ArrayLogger $logger */
        $logger = $this->logger;
        return $logger->get();
    }

    public function run(UpdateCheckData $data)
    {
        $slug = Slug::createFromUrl($this->repoUrl);
        /** @var ProviderInterface $provider */
        $provider = ProviderFactory::createFromSlugAndUrl($slug, $this->repoUrl);
        $provider->authenticate($this->token);
        $file_to_fetch = 'composer.json';
        if ($this->directory) {
            $file_to_fetch = sprintf('%s/composer.json', $this->directory);
        }
        $composer_json = $provider->getFileFromSlug($slug, $file_to_fetch);
        $json = @json_decode($composer_json);
        if (!$json) {
            throw new \Exception('The composer.json found was not valid JSON');
        }
        $auth_json = NULL;
        // Also see if we have an auth.json file.
        try {
            $file_to_fetch = 'auth.json';
            if ($this->directory) {
                $file_to_fetch = sprintf('%s/composer.json', $this->directory);
            }
            $auth = $provider->getFileFromSlug($slug, $file_to_fetch);
            $auth_json = @json_decode($auth);
        }
        catch (\Throwable $e) {
            // Ignore that for a while.
        }
        $this->logger->info('composer.json data', [
            'data' => $json,
            'type' => 'composer.json',
            'url' => $slug->getUrl(),
        ]);
        $default_branch = $provider->getDefaultBranch($slug);
        $current_sha = $provider->getShaFromBranchAndSlug($default_branch, $slug);
        if (!$data->getLastSha() || $current_sha != $data->getLastSha()->getSha()) {
            // Needs update.
            $last_sha = 'unknown';
            $last_time = 'unknown';
            if ($data->getLastSha()) {
                $last_sha = $data->getLastSha()->getSha();
                $last_time = date('d.m.y H:i:s', $data->getLastSha()->getTimestamp());
            }
            $data->setLastSha(new UpdateCheckSha($current_sha, time()));
            $this->logger->info("Current sha $current_sha is not the same as last sha $last_sha (from $last_time)");
            return new NeedsUpdateResult($data);
        }
        foreach (['require', 'require-dev'] as $type) {
            if (!isset($json->{$type})) {
                continue;
            }
            foreach ($json->{$type} as $dep => $version) {
                $parts = explode('/', $dep);
                if (count($parts) === 1) {
                    // This usually means it is a meta-package. Like ext-curl, or php. We
                    // do not need to check those.
                    continue;
                }
                $sha = $data->getShaForPackage($dep);
                if (!$sha) {
                    // For sure needs an update.
                    $result = new NeedsUpdateResult($data);
                    $result->setPackage($dep);
                    if ($new_sha = $this->getNewShaForPackage($dep, $composer_json, $auth_json)) {
                        $this->logger->info('Current package sha for package @package is @sha', [
                            '@package' => $dep,
                            '@sha' => $new_sha,
                        ]);
                        $new_sha_object = new UpdateCheckSha($new_sha, time());
                        $data->setShaForPackage($dep, $new_sha_object);
                        $this->logger->info("No sha stored for package $dep");
                        $result->setSha($new_sha_object);
                        return $result;
                    }
                    $this->logger->warning('Sha for package @package was not stored, but also not found when looking for it. Thus it will be not found on next run as well. Hopefully we will be able to locate it then', [
                        '@package' => $dep,
                    ]);
                    $this->logger->info("No sha stored for package $dep");
                    return $result;
                }
                $current_package_sha = $this->getNewShaForPackage($dep, $composer_json, $auth_json);
                $this->logger->info('Current package sha for package @package is @sha', [
                    '@package' => $dep,
                    '@sha' => $current_package_sha,
                ]);
                if (FALSE === $current_package_sha) {
                    // Some error prevented us from finding the sha, We do not want to
                    // store that, so we keep on checking other packages.
                    continue;
                }
                if ($sha->getSha() == $current_package_sha) {
                    // Still the same. Keep checking.
                    continue;
                }
                // Run update.
                $new_sha = new UpdateCheckSha($current_package_sha, time());
                $data->setShaForPackage($dep, $new_sha);
                $old_sha = $sha->getSha();
                $old_time = date('d.m.Y H:i:s', $sha->getTimestamp());
                $this->logger->info("Sha for package $dep ($current_package_sha) is different than sha we had stored ($old_sha from $old_time)");
                $result = new NeedsUpdateResult($data);
                $result->setSha($new_sha);
                $result->setPackage($dep);
                return $result;
            }
        }
        return new NeedsUpdateResult($data, NeedsUpdateResult::DOES_NOT_NEED_UPDATE);
    }

    protected function getNewShaForPackage($package, $composer_json, $auth_json = NULL)
    {
        // We trust the fact that other packages that require the same name does not
        // do so from a completely different source.
        $cid = sprintf('violinist_needs_update_runner_%s', md5($package));
        $cache_data = $this->cache->getItem($cid);
        $data = $cache_data->get();
        if ($data) {
            return $data;
        }
        // Run composer show in a directory where we have put the composer.json.
        $uniqid = uniqid('violinist_needs_update_runner', true);
        $directory = "/tmp/$uniqid";
        if (!mkdir($directory) && !is_dir($directory)) {
            return FALSE;
        }
        file_put_contents("$directory/composer.json", $composer_json);
        if ($auth_json) {
            file_put_contents("$directory/auth.json", json_encode($auth_json));
        }
        $project_root = __DIR__ . '/..';
        $composer_path_suffix = '/vendor/bin/composer';
        $composer = $project_root . $composer_path_suffix;
        if (!file_exists($composer)) {
            $this->logger->error('No composer found on path @path', [
                '@path' => $composer,
            ]);
            return FALSE;
        }

        $keys = array_filter(array_map(function ($name) {
            /** @var \Codeaken\SshKey\SshKeyPair $key */
            $key = $this->{$name};
            if (!$key) {
                // That seems horrible.
                return FALSE;
            }
            $filename = "/tmp/$name";
            $filename_pub = "$filename.pub";
            file_put_contents($filename, $key->getPrivateKey()->getKeyData(SshKey::FORMAT_PKCS8));
            chmod($filename, 0600);
            /** @var \Codeaken\SshKey\SshPublicKey $pub */
            $pub = $key->getPublicKey();
            file_put_contents($filename_pub, $pub->getKeyData(SshKey::FORMAT_OPENSSH));
            chmod($filename_pub, 0600);
            $command = ["ssh-add", $filename];
            $process = new Process($command);
            $process->run();
            return [
                'key' => $key,
                'filename_pub' => $filename_pub,
            ];
        }, ['userKey', 'projectKey']));
        $process = new Process([
            $composer,
            "show",
            $package,
            "--all",
            "-d",
            $directory,
        ], NULL, [
            'COMPOSER_DISABLE_XDEBUG_WARN' => 1,
            'COMPOSER_ALLOW_SUPERUSER' => 1,
            'COMPOSER_HOME' => $project_root,
        ]);
        $process->setTimeout(30);
        $our_sha = FALSE;
        try {
            $start = microtime(TRUE);
            $process->inheritEnvironmentVariables(TRUE);
            $process->run();
            $this->logger->info('Ran composer show for @package in @sec seconds', [
                '@package' => $package,
                '@sec' => microtime(TRUE) - $start,
            ]);
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            // Theoretically, a package can be not found with composer show, but still
            // installable. This is because the lock file could contain specific
            // instructions, but the composer.json does not contain enough info to
            // find it. So we check for that output before we decide the package check
            // was not a failure.
            if (!$our_sha && empty($stdout)) {
                $this->logger->info('Package: @package. Stdout: @stdout. Stderr: @stderr', [
                    '@package' => $package,
                    '@stdout' => $stdout,
                    '@stderr' => $stderr,
                ]);
                $this->logger->info(sprintf('Exit code from process checking update for %s was %d', $package, $process->getExitCode()));
                return FALSE;
            }
            $json_of_output = json_encode($stdout);
            $our_sha = $our_sha ? $our_sha : md5($json_of_output);
            // Now, let's see if we can parse it so we can actually use the commit
            // sha of the package.
            $lines = preg_split('/\r\n|\r|\n/', $stdout);
            foreach ($lines as $line) {
                if (strpos($line, 'source') === FALSE) {
                    continue;
                }
                // Now split on space and use the last in the array, if it is of the
                // correct length.
                $cols = explode(' ', $line);
                if ($cols[4] == '[git]' && strlen($cols[count($cols) - 1]) === 40) {
                    // Hopefully that is a git sha.
                    $our_sha = $cols[count($cols) - 1];
                    break;
                }
            }
        }
        catch (\Exception $e) {
            $this->logger->error('Caught exception for process: @msg', [
                '@msg' => $e->getMessage(),
            ]);
            if ($process) {
                $this->logger->info('Trying to stop process');
                $process_exit_code = $process->stop();
                if ($process_exit_code) {
                    $this->logger->info('Process stop exit code: @code', [
                        '@code' => $process_exit_code,
                    ]);
                }
            }
        }
        // Clean up.
        @unlink("$directory/composer.json");
        if ($auth_json) {
            @unlink("$directory/auth.json");
        }
        // For some reason, some times there is an empty directory called "vendor"
        // in there. Not sure why.
        if (@file_exists("$directory/vendor")) {
            @rmdir("$directory/vendor");
        }
        @rmdir($directory);
        if ($our_sha) {
            $cache_data->set($our_sha);
            $cache_data->expiresAfter(600);
            $this->cache->save($cache_data);
        }
        array_map(function ($key) {
            $filename_pub = $key['filename_pub'];
            $command = ["ssh-add", "-d", "$filename_pub"];
            $process = new Process($command);
            $process->run();
        }, $keys);
        return $our_sha;
    }
}

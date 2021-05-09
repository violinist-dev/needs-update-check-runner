<?php

namespace Violinist\NeedsUpdateCheckRunner\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

class CachedRemoteFileSystemTest extends TestCase {

    public function setUp()
    {
        try {
            $env = new Dotenv();
            $env->load(__DIR__ . '/../../.env');
        } catch (\Throwable $e) {
            // We tried.
        }
    }

    public function testCached() {
        $our_dir = sprintf('%s/%s', sys_get_temp_dir(), uniqid('vnucr-test-', true));
        // If we run composer show 2 times, we should have cached the second time, yeah?
        $debug_output = $this->runContainerWithComposerShow($our_dir);
        // This should not have triggered it.
        self::assertNotContains('Forcing disk cache for URL https://repo.packagist.org/packages.json', $debug_output);
        // However, running it one more time should certainly trigger it.
        $debug_output = $this->runContainerWithComposerShow($our_dir);
        self::assertContains('Forcing disk cache for URL https://repo.packagist.org/packages.json', $debug_output);
    }

    protected function runContainerWithComposerShow($our_dir) {
        $process = new Process(sprintf(
            'docker run --rm -v %s:/tmp/symfony-cache needs-update-check-runner /usr/src/myapp/vendor/bin/composer show psr/cache -a -vvv',
            $our_dir
        ), null, null, null, 600);
        $process->run();
        if ($process->getExitCode()) {
            var_export($process->getOutput());
        }
        $this->assertEquals(0, $process->getExitCode(), 'Docker did not exit with exit code 0');
        $debug_output = $process->getErrorOutput();
        return $debug_output;
    }
}

<?php

/**
 * @file
 * Runner.
 *
 * @author eiriksm <eirik@morland.no>
 */

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Dotenv\Dotenv;

require_once "vendor/autoload.php";

$container = new ContainerBuilder();
$container->register('cache', FilesystemAdapter::class);
$container->register('logger', 'Wa72\SimpleLogger\ArrayLogger');
$container->register('runner', 'Violinist\NeedsUpdateCheckRunner\Runner')
    ->addMethodCall('setLogger', [new Reference('logger')])
    ->addMethodCall('setCache', [new Reference('cache')]);

/** @var \Violinist\NeedsUpdateCheckRunner\Runner $runner */
$runner = $container->get('runner');
$runner->setRepoUrl($_SERVER['project_url']);
$runner->setToken($_SERVER['user_token']);
$code = 0;
try {
  $env = new Dotenv();
  $env->load(__DIR__ . '/.env');
}
catch (Throwable $e) {
  // We tried.
}

$update_check_data = NULL;
$output = [];
try {
    $update_check_data = @unserialize(@json_decode($_SERVER['update_check_data']));
    assert($update_check_data instanceof \Violinist\UpdateCheckData\UpdateCheckData);
} catch (Throwable $e) {
    $output[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => 'info',
        'message' => 'Runner exception',
        'context' => [
            'type' => 'error',
            'data' => [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ],
        ]
    ];
    print json_encode($output);
    exit(1);
}
try {
    /** @var \Violinist\NeedsUpdateCheckRunner\NeedsUpdateResult $result */
    $result = $runner->run($update_check_data);
    if ($result->needsUpdate()) {
        foreach ($runner->getMessages() as $message) {
            $output[] = $message;
        }
        $output[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'info',
            'message' => 'Update needed',
            'context' => [
                'type' => 'result',
                'data' => serialize($result->getData()),
                'sha' => $result->getSha(),
                'package' => $result->getPackage(),
            ]
        ];
    }
    print json_encode($output);
}
catch (Throwable $e) {
    $output[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => 'info',
        'message' => 'Runner exception',
        'context' => [
            'type' => 'error',
            'data' => [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ],
        ]
    ];
    print json_encode($output);
    exit(1);
}

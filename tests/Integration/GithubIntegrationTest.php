<?php

namespace Violinist\NeedsUpdateCheckRunner\Tests\Integration;

use Violinist\UpdateCheckData\UpdateCheckData;

class GithubIntegrationTest extends IntegrationBase
{
    public function testGithubEmptyKnown()
    {
        $url = getenv('PRIVATE_REPO_GITHUB');
        $token = getenv('PRIVATE_USER_TOKEN_GITHUB');
        $data = new UpdateCheckData();
        $result = $this->runContainer([
            'project_url' => $url,
            'user_token' => $token,
            'update_check_data' => sprintf("'%s'", json_encode(serialize($data))),
        ]);
        $result_found = false;
        foreach ($result as $item) {
            if (empty($item->context->type)) {
                continue;
            }
            if ($item->context->type !== 'result') {
                continue;
            }
            $data = unserialize($item->context->data);
            if (!$data instanceof UpdateCheckData) {
                continue;
            }
            self::assertNotEmpty($data->getLastSha()->getSha());
            $result_found = true;
        }
        self::assertEquals(true, $result_found);
    }
}

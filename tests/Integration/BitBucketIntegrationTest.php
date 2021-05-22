<?php

namespace Violinist\NeedsUpdateCheckRunner\Tests\Integration;

use League\OAuth2\Client\Provider\AbstractProvider;
use Stevenmaguire\OAuth2\Client\Provider\Bitbucket;
use Violinist\UpdateCheckData\UpdateCheckData;

class BitBucketIntegrationTest extends IntegrationBase
{
    public function testBitbucketEmptyKnown()
    {
        $provider = new Bitbucket([
            'clientId' => getenv('BITBUCKET_CLIENT_ID'),
            'clientSecret' => getenv('BITBUCKET_CLIENT_SECRET'),
            'redirectUri' => getenv('BITBUCKET_REDIRECT_URI'),
        ]);
        $new_token = $provider->getAccessToken('refresh_token', [
            'refresh_token' => getenv('BITBUCKET_REFRESH_TOKEN'),
        ]);
        $url = getenv('PRIVATE_REPO_BITBUCKET');
        $data = new UpdateCheckData();
        $result = $this->runContainer([
            'project_url' => $url,
            'user_token' => $new_token->getToken(),
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

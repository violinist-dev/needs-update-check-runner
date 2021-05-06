<?php

namespace Violinist\NeedsUpdateCheckRunner;

use Violinist\UpdateCheckData\UpdateCheckData;
use Violinist\UpdateCheckData\UpdateCheckSha;

class NeedsUpdateResult
{
    const NEEDS_UPDATE = 1;
    const DOES_NOT_NEED_UPDATE = 0;

    /**
     * @var UpdateCheckSha
     */
    private $sha;

    /**
     * @var string
     */
    private $package;

    /**
     * @var int
     */
    private $type = 1;

    /**
     * @var UpdateCheckData
     */
    private $data;

    public function __construct(UpdateCheckData $data, $type = self::NEEDS_UPDATE)
    {
        $this->data = $data;
        $this->type = $type;
    }

    public function setPackage($package)
    {
        $this->package = $package;
    }

    public function setSha(UpdateCheckSha $sha)
    {
        $this->sha = $sha;
    }

    public function needsUpdate()
    {
        return $this->type === self::NEEDS_UPDATE;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getSha()
    {
        return $this->sha;
    }

    public function getPackage()
    {
        return $this->package;
    }
}

<?php

namespace OpenDominion\Contracts\Services\Dominion\Actions;

use Exception;
use OpenDominion\Models\Dominion;
use RuntimeException;

interface ExploreActionService
{
    /**
     * Does an explore action for a Dominion.
     *
     * @param Dominion $dominion
     * @param array $data
     * @return array
     * @throws Exception
     * @throws RuntimeException
     */
    public function explore(Dominion $dominion, array $data);
}
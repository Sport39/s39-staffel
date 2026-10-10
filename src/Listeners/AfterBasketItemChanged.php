<?php

namespace S39Staffel\Listeners;

use S39Staffel\Services\StaffelService;

/**
 * Nach jeder Aenderung im Warenkorb: Staffel ueber alle Varianten neu rechnen.
 */
class AfterBasketItemChanged
{
    public function handle($event)
    {
        /** @var StaffelService $service */
        $service = pluginApp(StaffelService::class);
        $service->recalculate('after');
    }
}

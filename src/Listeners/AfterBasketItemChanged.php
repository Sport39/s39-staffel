<?php

namespace S39Staffel\Listeners;

use S39Staffel\Services\StaffelService;

/**
 * Nach jeder Aenderung im Warenkorb: uebrige Positionen des Artikels nachziehen.
 */
class AfterBasketItemChanged
{
    public function handle($event)
    {
        /** @var StaffelService $service */
        $service = pluginApp(StaffelService::class);
        if (!$service->active()) {
            return;
        }
        $service->recalculate();
    }
}

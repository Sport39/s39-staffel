<?php

namespace S39Staffel\Listeners;

use S39Staffel\Services\StaffelService;

/**
 * Testmodus 3: Preis direkt am Warenkorb-Modell setzen, bevor es gespeichert wird.
 */
class BeforeBasketItemChanged
{
    public function handle($event)
    {
        /** @var StaffelService $service */
        $service = pluginApp(StaffelService::class);
        if (!$service->usesBeforeMode()) {
            return;
        }
        try {
            $basketItem = $event->getBasketItem();
            $service->applyToModel($basketItem);
        } catch (\Throwable $e) {
            $service->logError('before', $e);
        }
    }
}

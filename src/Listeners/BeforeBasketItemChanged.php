<?php

namespace S39Staffel\Listeners;

use S39Staffel\Services\StaffelService;

/**
 * Vor dem Speichern einer Position: Preis am Modell setzen.
 */
class BeforeBasketItemChanged
{
    public function handle($event)
    {
        /** @var StaffelService $service */
        $service = pluginApp(StaffelService::class);
        if (!$service->active()) {
            return;
        }
        try {
            $service->applyToModel($event->getBasketItem());
        } catch (\Throwable $e) {
            $service->logError('before', $e);
        }
    }
}

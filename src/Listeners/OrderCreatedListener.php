<?php

namespace S39Staffel\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use S39Staffel\Services\OrderFileService;

/**
 * Nach dem Anlegen eines Auftrags: vom Kunden hochgeladene Dateien
 * (Auftragsmerkmale vom Typ Datei, z. B. Vereinslogos) in die
 * Auftragsdokumente kopieren, damit sie als E-Mail-Anhang nutzbar sind.
 */
class OrderCreatedListener
{
    public function handle(OrderCreated $event)
    {
        /** @var OrderFileService $service */
        $service = pluginApp(OrderFileService::class);
        if (!$service->active()) {
            return;
        }
        try {
            $service->copyPropertyFiles($event->getOrder());
        } catch (\Throwable $e) {
            $service->logError('orderCreated', $e);
        }
    }
}

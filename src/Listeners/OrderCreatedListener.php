<?php

namespace S39Staffel\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use S39Staffel\Services\OrderFileService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

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
            $order = $event->getOrder();
            $orderId = (int)$order->id;
            $service->note('event', ['orderId' => $orderId]);
            // Auftrag mit allen Positionen/Eigenschaften neu laden (im Event sind die Relationen nicht sicher geladen)
            /** @var AuthHelper $auth */
            $auth = pluginApp(AuthHelper::class);
            $full = $auth->processUnguarded(function () use ($orderId) {
                /** @var OrderRepositoryContract $repo */
                $repo = pluginApp(OrderRepositoryContract::class);
                return $repo->findOrderById($orderId, ['orderItems', 'orderItems.properties']);
            });
            // 1) Upload-Schluessel aus den Textmerkmalen (Ref) bzw. dem Warenkorb ermitteln
            $keys = $service->refFiles($full ?: $order);
            if (!count($keys)) {
                $keys = $service->basketFiles();
            }
            $done = [];
            if (count($keys)) {
                $done = $auth->processUnguarded(function () use ($service, $orderId, $keys) {
                    return $service->attachFiles($orderId, $keys);
                });
            }
            // 2) Fallback: Datei-Merkmale des Auftrags selbst
            if (!count($done)) {
                $auth->processUnguarded(function () use ($service, $full, $order) {
                    $service->copyPropertyFiles($full ?: $order);
                });
            }
            $auth->processUnguarded(function () use ($service, $orderId) {
                $service->attachDiag($orderId, date('c') . "\n" . implode("\n", $service->diag) . "\n");
            });
        } catch (\Throwable $e) {
            $service->logError('orderCreated', $e);
        }
    }
}

<?php

namespace S39Staffel\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use S39Staffel\Services\OrderFileService;
use S39Staffel\Services\PrintListService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

/**
 * Nach dem Anlegen eines Auftrags:
 *  - Druckliste (Personalisierung als Tabelle) als PDF erzeugen; Druckvorschauen und
 *    Bild-Logos werden als Seiten in dieses PDF eingebettet
 *  - vom Kunden hochgeladene PDF-Dateien (z. B. Vektor-Logo) zusaetzlich als eigenes Dokument anhaengen
 * Plenty speichert externe Dokumente grundsaetzlich als PDF, deshalb werden keine
 * Rohbilder (PNG/JPG) mehr als eigene Dokumente abgelegt.
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
            /** @var AuthHelper $auth */
            $auth = pluginApp(AuthHelper::class);
            $full = $auth->processUnguarded(function () use ($orderId) {
                /** @var OrderRepositoryContract $repo */
                $repo = pluginApp(OrderRepositoryContract::class);
                return $repo->findOrderById($orderId, ['orderItems', 'orderItems.properties']);
            });
            /** @var PrintListService $printList */
            $printList = pluginApp(PrintListService::class);
            $col = $printList->collect($full ?: $order);
            $images = $col['images'];
            $pdfs = $col['pdfs'];
            $pdf = $printList->build($full ?: $order, $images, $col['links'] ?? []);
            $auth->processUnguarded(function () use ($printList, $orderId, $pdf, $pdfs) {
                if ($pdf !== '') {
                    $printList->attach($orderId, $pdf);
                }
                foreach ($pdfs as $name => $body) {
                    $printList->attach($orderId, $body, $name);
                }
            });
            $service->note('files', ['orderId' => $orderId, 'druckliste' => strlen($pdf), 'images' => count($images), 'pdfs' => array_keys($pdfs), 'skipped' => $col['skipped'], 'diag' => $service->diag]);
        } catch (\Throwable $e) {
            $service->logError('orderCreated', $e);
        }
    }
}

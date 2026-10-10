<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use S39Staffel\Services\OrderFileService;

/**
 * Manuelles Anstossen: /rest/s39staffel/attach?orderId=123
 * Kopiert die Datei-Merkmale des Auftrags in die Auftragsdokumente
 * (nur fuer Auftraege der letzten 24 Stunden; kopiert ausschliesslich
 * Dateien, die zum Auftrag selbst gehoeren).
 */
class OrderFileController extends Controller
{
    public function run(Request $request, Response $response, OrderFileService $service)
    {
        $orderId = (int)$request->get('orderId', 0);
        if ($orderId <= 0) {
            return $response->json(['error' => 'orderId fehlt'], 400);
        }
        try {
            /** @var AuthHelper $auth */
            $auth = pluginApp(AuthHelper::class);
            $order = $auth->processUnguarded(function () use ($orderId) {
                /** @var OrderRepositoryContract $repo */
                $repo = pluginApp(OrderRepositoryContract::class);
                return $repo->findOrderById($orderId);
            });
            if (!$order) {
                return $response->json(['error' => 'Auftrag nicht gefunden'], 404);
            }
            $created = strtotime((string)$order->createdAt);
            if ($created && $created < time() - 86400) {
                return $response->json(['error' => 'Auftrag zu alt'], 403);
            }
            $files = $auth->processUnguarded(function () use ($service, $order) {
                return $service->copyPropertyFiles($order);
            });
            return $response->json(['orderId' => $orderId, 'files' => $files]);
        } catch (\Throwable $e) {
            $service->logError('attach ' . $orderId, $e);
            return $response->json(['error' => $e->getMessage()], 500);
        }
    }
}

<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use S39Staffel\Services\PrintListService;

/**
 * Druckliste eines Auftrags als PDF:
 *   /rest/s39staffel/druckliste?orderId=123          -> PDF anzeigen
 *   /rest/s39staffel/druckliste?orderId=123&attach=1 -> zusaetzlich als Auftragsdokument ablegen
 *   /rest/s39staffel/druckliste?orderId=123&json=1   -> Rohdaten
 * Nur fuer Auftraege der letzten 24 Stunden (Testphase).
 */
class PrintListController extends Controller
{
    public function run(Request $request, Response $response, PrintListService $service)
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
                return $repo->findOrderById($orderId, ['orderItems', 'orderItems.properties']);
            });
            if (!$order) {
                return $response->json(['error' => 'Auftrag nicht gefunden'], 404);
            }
            $created = strtotime((string)$order->createdAt);
            if ($created && $created < time() - 86400 * 3) {
                return $response->json(['error' => 'Auftrag zu alt'], 403);
            }
            if ((string)$request->get('json', '') === '1') {
                return $response->json($service->parse($order));
            }
            $col = $auth->processUnguarded(function () use ($service, $order) {
                return $service->collect($order);
            });
            if ((string)$request->get('files', '') === '1') {
                return $response->json(['images' => array_map(function ($i) {
                    return $i['title'] . ' (' . strlen($i['body']) . ' B)';
                }, $col['images']), 'pdfs' => array_keys($col['pdfs']), 'skipped' => $col['skipped']]);
            }
            $pdf = $service->build($order, $col['images'], $col['links'] ?? []);
            if ($pdf === '') {
                return $response->json(['error' => 'keine Druckdaten im Auftrag'], 404);
            }
            $attached = false;
            if ((string)$request->get('attach', '') === '1') {
                $attached = $auth->processUnguarded(function () use ($service, $orderId, $pdf, $col) {
                    $ok = $service->attach($orderId, $pdf);
                    foreach ($col['pdfs'] as $name => $body) {
                        $service->attach($orderId, $body, $name);
                    }
                    return $ok;
                });
                return $response->json(['ok' => $attached, 'bytes' => strlen($pdf)]);
            }
            return $response->make($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Druckliste_' . $orderId . '.pdf"',
            ]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => basename($e->getFile())], 500);
        }
    }
}

<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;
use S39Staffel\Services\StaffelService;

/**
 * Debug-Endpunkte (nur zum Testen):
 *  /s39staffel/ping  -> Konfiguration + aktueller Warenkorb
 *  /s39staffel/run   -> Staffel-Berechnung manuell anstossen und Ergebnis zurueckgeben
 */
class PingController extends Controller
{
    public function ping(Response $response, StaffelService $service, BasketItemRepositoryContract $itemRepo)
    {
        return $response->json([
            'plugin' => 'S39Staffel',
            'mode' => $service->mode(),
            'basket' => $this->basket($itemRepo),
        ]);
    }

    public function run(Response $response, StaffelService $service, BasketItemRepositoryContract $itemRepo)
    {
        $result = $service->recalculateWithReport('manual');
        return $response->json([
            'plugin' => 'S39Staffel',
            'mode' => $service->mode(),
            'report' => $result,
            'basket' => $this->basket($itemRepo),
        ]);
    }

    private function basket(BasketItemRepositoryContract $itemRepo): array
    {
        $out = [];
        foreach ($itemRepo->all() as $bi) {
            $out[] = [
                'id' => $bi->id,
                'itemId' => $bi->itemId,
                'variationId' => $bi->variationId,
                'quantity' => $bi->quantity,
                'price' => $bi->price,
                'givenPrice' => $bi->givenPrice,
                'useGivenPrice' => $bi->useGivenPrice,
                'priceId' => $bi->priceId,
                'rebate' => $bi->rebate,
                'itemType' => $bi->itemType,
            ];
        }
        return $out;
    }
}

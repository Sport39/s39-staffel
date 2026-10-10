<?php

namespace S39Staffel\Services;

use Plenty\Plugin\Application;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Item\SalesPrice\Contracts\SalesPriceSearchRepositoryContract;
use Plenty\Modules\Item\SalesPrice\Models\SalesPriceSearchRequest;
use Plenty\Modules\Webshop\Contracts\CheckoutRepositoryContract;
use Plenty\Modules\Webshop\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;

/**
 * Rechnet die Staffelpreise im Warenkorb ueber alle Varianten (Groessen)
 * eines Artikels zusammen und setzt den so ermittelten Preis je Position.
 *
 * Testmodi (Plugin-Konfiguration "mode"):
 *  0 = aus, nur protokollieren
 *  1 = updateBasketItem(id, ['givenPrice' => x, 'useGivenPrice' => true])
 *  2 = updateBasketItem(id, ['price' => x])
 *  3 = Modell im Before-Event aendern ($basketItem->givenPrice / useGivenPrice / price)
 *  4 = 1, 2 und 3 nacheinander
 */
class StaffelService
{
    use Loggable;

    /** @var bool Schutz gegen Rekursion (updateBasketItem loest selbst Events aus) */
    private static $running = false;

    /** @var array Zwischenspeicher: Variation-ID => Gruppenschluessel */
    private static $groupCache = [];

    /** @var ConfigRepository */
    private $config;

    /** @var array Bericht des letzten Laufs (fuer Debug-Endpunkt) */
    private $report = [];

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    /* ---------------- Konfiguration ---------------- */

    private function cfg(string $key, $default = null)
    {
        $v = $this->config->get('S39Staffel.settings.' . $key);
        if ($v === null || $v === '') {
            $v = $this->config->get('S39Staffel.' . $key);
        }
        return ($v === null || $v === '') ? $default : $v;
    }

    public function mode(): int
    {
        return (int)$this->cfg('mode', 0);
    }

    public function usesBeforeMode(): bool
    {
        $m = $this->mode();
        return $m === 3 || $m === 4;
    }

    private function verbose(): bool
    {
        $v = $this->cfg('log', true);
        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    private function log(string $key, array $data = [])
    {
        $this->report[] = ['key' => $key, 'data' => $data];
        if (!$this->verbose() && $key !== 'error') {
            return;
        }
        try {
            $this->getLogger('S39Staffel')->info('S39Staffel::log.' . $key, $data);
        } catch (\Throwable $e) {
        }
    }

    public function logError(string $where, \Throwable $e)
    {
        $this->report[] = ['key' => 'error', 'where' => $where, 'message' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()];
        try {
            $this->getLogger('S39Staffel')->error('S39Staffel::log.error', [
                'where' => $where,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (\Throwable $x) {
        }
    }

    /* ---------------- Hauptlauf (After-Events) ---------------- */

    public function recalculateWithReport(string $trigger): array
    {
        $this->report = [];
        self::$running = false;
        $this->recalculate($trigger);
        return $this->report;
    }

    public function recalculate(string $trigger)
    {
        if (self::$running) {
            return;
        }
        $mode = $this->mode();
        self::$running = true;
        try {
            /** @var BasketItemRepositoryContract $itemRepo */
            $itemRepo = pluginApp(BasketItemRepositoryContract::class);
            $items = $itemRepo->all();
            $plan = $this->plan($items);

            $this->log('run', [
                'trigger' => $trigger,
                'mode' => $mode,
                'items' => count($items),
                'plan' => $plan['log'],
            ]);

            if ($mode === 0) {
                return;
            }

            foreach ($plan['set'] as $row) {
                $this->applyToRepository($itemRepo, $row, $mode);
            }

            // Kontrolle: Preise nach dem Setzen erneut lesen
            if ($this->verbose()) {
                $check = [];
                foreach ($itemRepo->all() as $bi) {
                    $check[] = $bi->id . ':' . $bi->variationId . ' x' . $bi->quantity
                        . ' price=' . $bi->price . ' given=' . $bi->givenPrice
                        . ' use=' . ($bi->useGivenPrice ? '1' : '0');
                }
                $this->log('set', ['after' => $check]);
            }
        } catch (\Throwable $e) {
            $this->logError('recalculate', $e);
        } finally {
            self::$running = false;
        }
    }

    /**
     * Ermittelt fuer jede Warenkorb-Position den Preis, der bei der
     * Gesamtmenge der Gruppe (Artikel bzw. Artikel+Farbe) gilt.
     */
    private function plan($items): array
    {
        $groups = [];
        foreach ($items as $bi) {
            if (!($bi instanceof BasketItem)) {
                continue;
            }
            if ((int)$bi->itemType === (int)BasketItem::BASKET_ITEM_TYPE_ITEM_SET) {
                // Sets, Bundles usw. nicht anfassen
                continue;
            }
            $key = $this->groupKey($bi);
            $groups[$key]['qty'] = ($groups[$key]['qty'] ?? 0) + (float)$bi->quantity;
            $groups[$key]['items'][] = $bi;
        }

        $set = [];
        $log = [];
        foreach ($groups as $key => $g) {
            $qty = $g['qty'];
            foreach ($g['items'] as $bi) {
                $target = $this->priceForQuantity($bi, $qty);
                $log[] = $key . ' | ' . $bi->id . ':' . $bi->variationId . ' x' . $bi->quantity
                    . ' => Gruppe ' . $qty . ' St. | aktuell ' . $bi->price
                    . ' | Ziel ' . ($target === null ? 'n/a' : $target['price'] . ' (PreisId ' . $target['priceId'] . ')');
                if ($target === null) {
                    continue;
                }
                $set[] = [
                    'id' => $bi->id,
                    'variationId' => $bi->variationId,
                    'quantity' => (float)$bi->quantity,
                    'current' => (float)$bi->price,
                    'price' => (float)$target['price'],
                    'priceNet' => (float)$target['priceNet'],
                    'priceId' => (int)$target['priceId'],
                ];
            }
        }
        return ['set' => $set, 'log' => $log];
    }

    private function applyToRepository(BasketItemRepositoryContract $itemRepo, array $row, int $mode)
    {
        $tries = [];
        if ($mode === 1 || $mode === 4) {
            $tries[] = ['givenPrice' => $row['price'], 'useGivenPrice' => true];
        }
        if ($mode === 2 || $mode === 4) {
            $tries[] = ['price' => $row['price']];
        }
        foreach ($tries as $data) {
            try {
                $res = $itemRepo->updateBasketItem($row['id'], $data, false);
                $after = $itemRepo->findOneById($row['id']);
                $this->log('set', [
                    'id' => $row['id'],
                    'data' => $data,
                    'before' => $row['current'],
                    'afterPrice' => $after ? $after->price : null,
                    'afterGiven' => $after ? $after->givenPrice : null,
                    'afterUse' => $after ? $after->useGivenPrice : null,
                    'result' => is_object($res) ? 'object' : (is_array($res) ? 'array' : 'other'),
                ]);
            } catch (\Throwable $e) {
                $this->logError('update ' . json_encode($data), $e);
            }
        }
    }

    /* ---------------- Before-Events (Modus 3/4) ---------------- */

    public function applyToModel($basketItem)
    {
        if (!($basketItem instanceof BasketItem)) {
            return;
        }
        /** @var BasketItemRepositoryContract $itemRepo */
        $itemRepo = pluginApp(BasketItemRepositoryContract::class);
        $qty = (float)$basketItem->quantity;
        $key = $this->groupKey($basketItem);
        foreach ($itemRepo->all() as $other) {
            if (!($other instanceof BasketItem) || $other->id == $basketItem->id) {
                continue;
            }
            if ($this->groupKey($other) === $key) {
                $qty += (float)$other->quantity;
            }
        }
        $target = $this->priceForQuantity($basketItem, $qty);
        $this->log('run', [
            'trigger' => 'before',
            'variationId' => $basketItem->variationId,
            'groupQty' => $qty,
            'target' => $target,
        ]);
        if ($target === null) {
            return;
        }
        $basketItem->givenPrice = (float)$target['price'];
        $basketItem->useGivenPrice = true;
        $basketItem->price = (float)$target['price'];
    }

    /* ---------------- Helfer ---------------- */

    private function groupKey(BasketItem $bi): string
    {
        $vid = (int)$bi->variationId;
        if (isset(self::$groupCache[$vid])) {
            return self::$groupCache[$vid];
        }
        $key = 'item' . (int)$bi->itemId;
        // Gruppierung je Artikel (Option 'color' folgt spaeter)
        self::$groupCache[$vid] = $key;
        return $key;
    }

    /**
     * Preis der Variante bei der angegebenen (Gruppen-)Menge laut Verkaufspreisen.
     */
    private function priceForQuantity(BasketItem $bi, float $quantity)
    {
        try {
            /** @var Application $app */
            $app = pluginApp(Application::class);
            /** @var CheckoutRepositoryContract $checkout */
            $checkout = pluginApp(CheckoutRepositoryContract::class);
            /** @var ContactRepositoryContract $contactRepo */
            $contactRepo = pluginApp(ContactRepositoryContract::class);
            /** @var BasketRepositoryContract $basketRepo */
            $basketRepo = pluginApp(BasketRepositoryContract::class);
            /** @var SalesPriceSearchRepositoryContract $priceRepo */
            $priceRepo = pluginApp(SalesPriceSearchRepositoryContract::class);

            $classId = 0;
            $singleAccess = 0;
            $contact = $contactRepo->getContact();
            if ($contact instanceof Contact) {
                $classId = (int)$contact->classId;
                $singleAccess = $contact->singleAccess;
            }
            $basket = $basketRepo->load();
            $referrerId = $basket && (int)$basket->referrerId > 0 ? (int)$basket->referrerId : 1;

            /** @var SalesPriceSearchRequest $req */
            $req = pluginApp(SalesPriceSearchRequest::class);
            $req->variationId = (int)$bi->variationId;
            $req->accountId = 0;
            $req->accountType = $singleAccess;
            $req->countryId = $checkout->getShippingCountryId();
            $req->currency = $checkout->getCurrency();
            $req->customerClassId = $classId;
            $req->plentyId = $app->getPlentyId();
            $req->quantity = $quantity;
            $req->referrerId = $referrerId;
            $req->type = 'default';

            $res = $priceRepo->search($req);
            if (!$res || !isset($res->price) || (float)$res->price <= 0) {
                return null;
            }
            return [
                'price' => (float)$res->price,
                'priceNet' => isset($res->priceNet) ? (float)$res->priceNet : 0.0,
                'priceId' => isset($res->salesPriceId) ? (int)$res->salesPriceId : 0,
            ];
        } catch (\Throwable $e) {
            $this->logError('priceForQuantity', $e);
            return null;
        }
    }
}

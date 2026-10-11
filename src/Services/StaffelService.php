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
 * eines Artikels zusammen.
 *
 * Plenty ermittelt Staffelpreise nur je Variante. Dieses Plugin zaehlt die
 * Mengen aller Varianten eines Artikels zusammen und setzt je Position den
 * Verkaufspreis, der fuer die Gesamtmenge gilt (als "givenPrice" am
 * Warenkorb-Modell - der einzige Weg, den Plenty beim Speichern uebernimmt).
 * Greift die Staffel nicht (mehr), wird der Fixpreis wieder freigegeben und
 * Plenty rechnet wie gewohnt.
 */
class StaffelService
{
    use Loggable;

    /** @var bool Schutz gegen Rekursion (updateBasketItem loest selbst Events aus) */
    private static $running = false;
    /** @var array Preis-Cache je Request: "variationId|menge" => Preis (null = kein Preis) */
    private static $priceCache = [];
    /** @var array|null Kontext (Kundenklasse, Land, Waehrung, ...) einmal je Request */
    private static $ctx = null;

    /** @var ConfigRepository */
    private $config;

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

    private function flag(string $key, bool $default): bool
    {
        $v = $this->cfg($key, $default);
        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    public function active(): bool
    {
        return $this->flag('active', true);
    }

    private function verbose(): bool
    {
        return $this->flag('log', false);
    }

    private function log(string $key, array $data = [])
    {
        if (!$this->verbose()) {
            return;
        }
        try {
            $this->getLogger('S39Staffel')->info('S39Staffel::log.' . $key, $data);
        } catch (\Throwable $e) {
        }
    }

    public function logError(string $where, \Throwable $e)
    {
        try {
            $this->getLogger('S39Staffel')->error('S39Staffel::log.error', [
                'where' => $where,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (\Throwable $x) {
        }
    }

    /* ---------------- After-Events: uebrige Positionen nachziehen ---------------- */

    public function recalculate()
    {
        if (self::$running) {
            return;
        }
        self::$running = true;
        try {
            /** @var BasketItemRepositoryContract $itemRepo */
            $itemRepo = pluginApp(BasketItemRepositoryContract::class);
            $items = $itemRepo->all();
            $plan = $this->plan($items);

            $this->log('run', ['items' => count($items), 'plan' => $plan['log']]);

            foreach ($plan['set'] as $row) {
                try {
                    // Update mit Events: BeforeBasketItemUpdate -> applyToModel setzt den Preis
                    $itemRepo->updateBasketItem($row['id'], [
                        'id' => $row['id'],
                        'variationId' => $row['variationId'],
                        'quantity' => $row['quantity'],
                    ], true);
                    $after = $itemRepo->findOneById($row['id']);
                    $this->log('set', [
                        'id' => $row['id'],
                        'before' => $row['current'],
                        'target' => $row['price'],
                        'after' => $after ? $after->price : null,
                    ]);
                } catch (\Throwable $e) {
                    $this->logError('update ' . $row['id'], $e);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('recalculate', $e);
        } finally {
            self::$running = false;
        }
    }

    /**
     * Ermittelt, welche Positionen angepasst werden muessen.
     */
    /** Diagnose fuer /rest/s39staffel/staffel */
    public function diagnose(): array
    {
        /** @var BasketItemRepositoryContract $itemRepo */
        $itemRepo = pluginApp(BasketItemRepositoryContract::class);
        $items = $itemRepo->all();
        $this->errors = [];
        $plan = $this->plan($items);
        return ['active' => $this->active(), 'items' => count($items), 'plan' => $plan['log'], 'set' => $plan['set'], 'errors' => $this->errors];
    }

    /** @var array */
    public $errors = [];

    private function plan($items): array
    {
        $groups = [];
        foreach ($items as $bi) {
            if (!($bi instanceof BasketItem) || $this->skip($bi)) {
                continue;
            }
            $key = (int)$bi->itemId;
            $groups[$key]['qty'] = ($groups[$key]['qty'] ?? 0) + (float)$bi->quantity;
            $groups[$key]['items'][] = $bi;
        }

        $set = [];
        $log = [];
        foreach ($groups as $key => $g) {
            $qty = $g['qty'];
            if (count($g['items']) < 2) {
                // Nur eine Zeile dieses Artikels: Gesamtmenge = eigene Menge, Plenty rechnet selbst.
                // Einzig ein frueher gesetzter Festpreis muss zurueckgenommen werden.
                $bi = $g['items'][0];
                if ((bool)$bi->useGivenPrice) {
                    $set[] = ['id' => $bi->id, 'variationId' => $bi->variationId, 'quantity' => (float)$bi->quantity, 'current' => (float)$bi->price, 'price' => (float)$bi->price];
                    $log[] = 'Artikel ' . $key . ' | ' . $bi->id . ' einzeln, Festpreis zuruecknehmen';
                }
                continue;
            }
            foreach ($g['items'] as $bi) {
                $target = $this->priceForQuantity($bi, $qty);
                $own = $this->priceForQuantity($bi, (float)$bi->quantity);
                $wantGiven = $target !== null && $own !== null && $target < $own - 0.005;
                $isGiven = (bool)$bi->useGivenPrice;
                $needs = $wantGiven
                    ? (!$isGiven || abs((float)$bi->price - $target) > 0.005)
                    : $isGiven;
                $log[] = 'Artikel ' . $key . ' | ' . $bi->id . ':' . $bi->variationId . ' x' . $bi->quantity
                    . ' | Gruppe ' . $qty . ' | aktuell ' . $bi->price . ($isGiven ? ' (fix)' : '')
                    . ' | eigen ' . ($own === null ? 'n/a' : $own) . ' | Ziel ' . ($target === null ? 'n/a' : $target)
                    . ' | ' . ($needs ? 'anpassen' : 'ok');
                if ($needs) {
                    $set[] = [
                        'id' => $bi->id,
                        'variationId' => $bi->variationId,
                        'quantity' => (float)$bi->quantity,
                        'current' => (float)$bi->price,
                        'price' => $target === null ? (float)$bi->price : $target,
                    ];
                }
            }
        }
        return ['set' => $set, 'log' => $log];
    }

    /* ---------------- Before-Events: Preis am Modell setzen ---------------- */

    public function applyToModel($basketItem)
    {
        if (!($basketItem instanceof BasketItem) || $this->skip($basketItem)) {
            return;
        }
        /** @var BasketItemRepositoryContract $itemRepo */
        $itemRepo = pluginApp(BasketItemRepositoryContract::class);
        $qty = (float)$basketItem->quantity;
        $itemId = (int)$basketItem->itemId;
        foreach ($itemRepo->all() as $other) {
            if (!($other instanceof BasketItem) || $other->id == $basketItem->id || $this->skip($other)) {
                continue;
            }
            if ((int)$other->itemId === $itemId) {
                $qty += (float)$other->quantity;
            }
        }
        if ($qty <= (float)$basketItem->quantity + 0.0001) {
            // keine weitere Zeile dieses Artikels im Warenkorb -> normaler Plenty-Preis
            $basketItem->useGivenPrice = false;
            $basketItem->givenPrice = 0;
            return;
        }
        $target = $this->priceForQuantity($basketItem, $qty);
        $own = $this->priceForQuantity($basketItem, (float)$basketItem->quantity);
        $this->log('before', [
            'variationId' => $basketItem->variationId,
            'quantity' => (float)$basketItem->quantity,
            'groupQty' => $qty,
            'own' => $own,
            'target' => $target,
        ]);
        if ($target === null || $own === null) {
            return;
        }
        if ($target >= $own - 0.005) {
            // Keine Verbesserung durch die Gesamtmenge: Plenty rechnet normal
            $basketItem->useGivenPrice = false;
            $basketItem->givenPrice = 0;
            return;
        }
        $basketItem->givenPrice = $target;
        $basketItem->useGivenPrice = true;
        $basketItem->price = $target;
    }

    /* ---------------- Helfer ---------------- */

    /** Sets, Bundles usw. nicht anfassen */
    private function skip(BasketItem $bi): bool
    {
        // Sets, sowie Hilfspositionen fuer Auftragsmerkmale (itemId -3, variationId 0)
        return (int)$bi->itemType === (int)BasketItem::BASKET_ITEM_TYPE_ITEM_SET
            || (int)$bi->variationId <= 0
            || (int)$bi->itemId <= 0;
    }

    /**
     * Bruttopreis der Variante bei der angegebenen Menge laut Verkaufspreisen
     * (beruecksichtigt Kundenklasse, Land, Waehrung, Referrer).
     */
    private function context(): array
    {
        if (self::$ctx !== null) {
            return self::$ctx;
        }
        /** @var Application $app */
        $app = pluginApp(Application::class);
        /** @var CheckoutRepositoryContract $checkout */
        $checkout = pluginApp(CheckoutRepositoryContract::class);
        /** @var ContactRepositoryContract $contactRepo */
        $contactRepo = pluginApp(ContactRepositoryContract::class);
        /** @var BasketRepositoryContract $basketRepo */
        $basketRepo = pluginApp(BasketRepositoryContract::class);
        $classId = 0;
        $singleAccess = 0;
        $contact = $contactRepo->getContact();
        if ($contact instanceof Contact) {
            $classId = (int)$contact->classId;
            $singleAccess = $contact->singleAccess;
        }
        $basket = $basketRepo->load();
        self::$ctx = [
            'classId' => $classId,
            'singleAccess' => $singleAccess,
            'referrerId' => $basket && (int)$basket->referrerId > 0 ? (int)$basket->referrerId : 1,
            'countryId' => $checkout->getShippingCountryId(),
            'currency' => $checkout->getCurrency(),
            'plentyId' => $app->getPlentyId(),
        ];
        return self::$ctx;
    }

    private function priceForQuantity(BasketItem $bi, float $quantity)
    {
        $cacheKey = (int)$bi->variationId . '|' . $quantity;
        if (array_key_exists($cacheKey, self::$priceCache)) {
            return self::$priceCache[$cacheKey];
        }
        try {
            $c = $this->context();
            /** @var SalesPriceSearchRepositoryContract $priceRepo */
            $priceRepo = pluginApp(SalesPriceSearchRepositoryContract::class);
            /** @var SalesPriceSearchRequest $req */
            $req = pluginApp(SalesPriceSearchRequest::class);
            $req->variationId = (int)$bi->variationId;
            $req->accountId = 0;
            $req->accountType = $c['singleAccess'];
            $req->countryId = $c['countryId'];
            $req->currency = $c['currency'];
            $req->customerClassId = $c['classId'];
            $req->plentyId = $c['plentyId'];
            $req->quantity = $quantity;
            $req->referrerId = $c['referrerId'];
            $req->type = 'default';

            $res = $priceRepo->search($req);
            if (!$res || !isset($res->price) || (float)$res->price <= 0) {
                $this->errors[] = 'noprice v' . $bi->variationId . ' q' . $quantity . ' class' . $c['classId'] . ' country' . $c['countryId'] . ' cur' . $c['currency'] . ' plenty' . $c['plentyId'] . ' ref' . $c['referrerId'] . ' res ' . ($res ? json_encode($res) : 'null');
                self::$priceCache[$cacheKey] = null;
                return null;
            }
            self::$priceCache[$cacheKey] = (float)$res->price;
            return (float)$res->price;
        } catch (\Throwable $e) {
            $this->errors[] = 'priceForQuantity ' . $e->getMessage();
            $this->logError('priceForQuantity', $e);
            return null;
        }
    }
}

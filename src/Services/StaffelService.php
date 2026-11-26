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
    /** @var array|null Warenkorb-Positionen (Cache je Request fuer die Before-Events) */
    private static $items = null;
    /** @var string Fingerabdruck des zuletzt geprueften Warenkorbs (Plenty feuert je Aenderung viele Events) */
    private static $checked = '';

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
        if (!$this->flag('active', true)) {
            return false;
        }
        // Optionale Test-Sperre: nur fuer bestimmte Kontakt-IDs
        $list = trim((string)$this->cfg('contactIds', ''));
        if ($list === '') {
            return true;
        }
        $ids = array_filter(array_map('intval', preg_split('/[^0-9]+/', $list)));
        if (!count($ids)) {
            return true;
        }
        try {
            /** @var ContactRepositoryContract $contactRepo */
            $contactRepo = pluginApp(ContactRepositoryContract::class);
            $cid = (int)$contactRepo->getContactId();
        } catch (\Throwable $e) {
            return false;
        }
        return $cid > 0 && in_array($cid, $ids, true);
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
            $items = $this->items(true);
            $fp = $this->fingerprint($items);
            if ($fp === self::$checked) {
                // Warenkorb seit der letzten Pruefung unveraendert -> nichts zu tun
                return;
            }
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
            self::$checked = $this->fingerprint(count($plan['set']) ? $this->items(true) : $items);
        } catch (\Throwable $e) {
            $this->logError('recalculate', $e);
        } finally {
            self::$running = false;
        }
    }

    /** Warenkorb-Positionen, je Request zwischengespeichert */
    private function items(bool $fresh = false)
    {
        if ($fresh || self::$items === null) {
            /** @var BasketItemRepositoryContract $itemRepo */
            $itemRepo = pluginApp(BasketItemRepositoryContract::class);
            try {
                self::$items = $itemRepo->all([], ['basketItemOrderParams']);
            } catch (\Throwable $e) {
                self::$items = $itemRepo->all();
            }
        }
        return self::$items;
    }

    private function fingerprint($items): string
    {
        $parts = [];
        foreach ($items as $bi) {
            if (!($bi instanceof BasketItem)) {
                continue;
            }
            $parts[] = $bi->id . ':' . $bi->variationId . ':' . $bi->quantity . ':' . $bi->price . ':' . ((bool)$bi->useGivenPrice ? 1 : 0);
        }
        sort($parts);
        return implode('|', $parts);
    }

    /**
     * Ermittelt, welche Positionen angepasst werden muessen.
     */
    /** Diagnose fuer /rest/s39staffel/staffel */
    public function diagnose(): array
    {
        /** @var BasketItemRepositoryContract $itemRepo */
        $items = $this->items(true);
        $this->errors = [];
        $plan = $this->plan($items);
        return ['active' => $this->active(), 'items' => count($items), 'plan' => $plan['log'], 'set' => $plan['set'], 'errors' => $this->errors];
    }

    /** @var array */
    public $errors = [];

    private function plan($items): array
    {
        $groups = [];
        $set = [];
        $log = [];
        foreach ($items as $bi) {
            if (!($bi instanceof BasketItem) || $this->skip($bi)) {
                continue;
            }
            if (isset(self::PROMO[(int)$bi->variationId])) {
                $promo = $this->promoPrice($bi, $items);
                $isGiven = (bool)$bi->useGivenPrice;
                $needs = $promo !== null
                    ? (!$isGiven || abs((float)$bi->price - $promo) > 0.005)
                    : $isGiven;
                $log[] = 'SPORT39 | ' . $bi->id . ':' . $bi->variationId . ' | aktuell ' . $bi->price . ($isGiven ? ' (fix)' : '')
                    . ' | Aktion ' . ($promo === null ? 'nein' : $promo) . ' | ' . ($needs ? 'anpassen' : 'ok')
                    . ' | Merkmale ' . json_encode($this->params($bi)) . ' | roh ' . $this->rawParams($bi);
                if ($needs) {
                    $set[] = ['id' => $bi->id, 'variationId' => $bi->variationId, 'quantity' => (float)$bi->quantity, 'current' => (float)$bi->price, 'price' => $promo === null ? (float)$bi->price : $promo];
                }
                continue;
            }
            $key = (int)$bi->itemId;
            $groups[$key]['qty'] = ($groups[$key]['qty'] ?? 0) + (float)$bi->quantity;
            $groups[$key]['items'][] = $bi;
        }

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
        if (isset(self::PROMO[(int)$basketItem->variationId])) {
            $promo = $this->promoPrice($basketItem, $this->items());
            if ($promo === null) {
                $basketItem->useGivenPrice = false;
                $basketItem->givenPrice = 0;
            } else {
                $basketItem->givenPrice = $promo;
                $basketItem->useGivenPrice = true;
                $basketItem->price = $promo;
            }
            return;
        }
        $qty = (float)$basketItem->quantity;
        $itemId = (int)$basketItem->itemId;
        foreach ($this->items() as $other) {
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

    /* ---------------- SPORT39-Aktion ---------------- */

    /**
     * SPORT39-Aktion: Wird "SPORT39" unter das Herstellerlogo gedruckt (Merkmal Druckposition
     * enthaelt "SPORT39") und kommt fuer denselben Artikel ein weiterer bezahlter Druck dazu,
     * kostet das Vereinswappen DTF 0,00 EUR und das Wappen 3D-TPU 3,00 EUR (wie im Team-Konfigurator).
     */
    const PROMO = [61107 => 0.0, 370986 => 3.0];
    /** bezahlte Druckarten (alles ausser Wappen) */
    const PAID = [61105, 61103, 61104, 17326, 17325, 61106, 17321, 17324, 214691, 214692, 214693, 214694];

    /** Diagnose: Rohdaten der Bestellmerkmale (gekuerzt) */
    private function rawParams($bi): string
    {
        $r = '';
        try {
            $arr = $bi->toArray();
            $r .= 'arr:' . json_encode($arr['basketItemOrderParams'] ?? null);
        } catch (\Throwable $e) {
            $r .= 'arr:err';
        }
        try {
            $r .= ' rel:' . json_encode($bi->basketItemOrderParams);
        } catch (\Throwable $e) {
            $r .= ' rel:err';
        }
        return mb_substr($r, 0, 600);
    }

    /** Bestellmerkmale einer Position als [propertyId => Wert] */
    private function params($bi): array
    {
        $out = [];
        $list = [];
        try {
            $arr = $bi->toArray();
            $list = $arr['basketItemOrderParams'] ?? [];
        } catch (\Throwable $e) {
        }
        if (!is_array($list) || !count($list)) {
            try {
                $rel = $bi->basketItemOrderParams;
                $list = is_array($rel) ? $rel : (is_object($rel) ? $rel->toArray() : []);
            } catch (\Throwable $e) {
                $list = [];
            }
        }
        foreach ($list as $p) {
            $p = json_decode(json_encode($p), true);
            if (!is_array($p)) {
                continue;
            }
            $id = (int)($p['propertyId'] ?? ($p['property']['id'] ?? ($p['id'] ?? 0)));
            $val = $p['value'] ?? ($p['property']['value'] ?? '');
            if ($id > 0) {
                $out[$id] = (is_array($val) || is_object($val) || $val === null) ? '' : (string)$val;
            }
        }
        return $out;
    }

    /** Aktionspreis oder null, wenn die Aktion nicht greift */
    private function promoPrice($bi, $items)
    {
        $vid = (int)$bi->variationId;
        if (!isset(self::PROMO[$vid])) {
            return null;
        }
        $p = $this->params($bi);
        $flag = stripos($p[128] ?? '', 'SPORT39') !== false || stripos($p[127] ?? '', 'SPORT39') !== false;
        if (!$flag) {
            return null;
        }
        $art = trim($p[127] ?? '');
        $isRef = strpos($art, '(Druck-Ref ') !== false;
        foreach ($items as $o) {
            if (!($o instanceof BasketItem) || $o->id == $bi->id || !in_array((int)$o->variationId, self::PAID, true)) {
                continue;
            }
            if ($isRef) {
                // Druckangaben ohne Merkmale (druck.json): ein weiterer bezahlter Druck im Warenkorb genuegt
                return self::PROMO[$vid];
            }
            $op = $this->params($o);
            if ($art !== '' && trim($op[127] ?? '') === $art) {
                return self::PROMO[$vid];
            }
        }
        return null;
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

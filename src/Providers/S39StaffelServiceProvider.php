<?php

namespace S39Staffel\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemUpdate;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemRemove;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemUpdate;
use S39Staffel\Listeners\AfterBasketItemChanged;
use S39Staffel\Listeners\BeforeBasketItemChanged;
use S39Staffel\Listeners\OrderCreatedListener;
use Plenty\Modules\Order\Events\OrderCreated;

/**
 * Staffelpreise ueber alle Varianten (Groessen) eines Artikels.
 *
 * Funktionsweise:
 *  - Vor dem Speichern einer Warenkorb-Position (Before-Events) wird der Preis
 *    am Modell gesetzt (givenPrice/useGivenPrice), wenn die Gesamtmenge des
 *    Artikels im Warenkorb eine bessere Staffel erreicht.
 *  - Nach jeder Aenderung (After-Events) werden die uebrigen Positionen des
 *    Artikels per Update mit Events nachgezogen.
 */
class S39StaffelServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->getApplication()->register(S39StaffelRouteServiceProvider::class);
    }

    public function boot(Dispatcher $dispatcher)
    {
        $dispatcher->listen(BeforeBasketItemAdd::class, BeforeBasketItemChanged::class);
        $dispatcher->listen(BeforeBasketItemUpdate::class, BeforeBasketItemChanged::class);
        $dispatcher->listen(AfterBasketItemAdd::class, AfterBasketItemChanged::class);
        $dispatcher->listen(AfterBasketItemUpdate::class, AfterBasketItemChanged::class);
        $dispatcher->listen(AfterBasketItemRemove::class, AfterBasketItemChanged::class);
        $dispatcher->listen(OrderCreated::class, OrderCreatedListener::class);
    }
}

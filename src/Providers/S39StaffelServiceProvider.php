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

/**
 * Registriert die Warenkorb-Ereignisse, nach denen die Staffel
 * ueber alle Varianten eines Artikels neu berechnet wird.
 */
class S39StaffelServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->getApplication()->register(S39StaffelRouteServiceProvider::class);
    }

    public function boot(Dispatcher $dispatcher)
    {
        $dispatcher->listen(AfterBasketItemAdd::class, AfterBasketItemChanged::class);
        $dispatcher->listen(AfterBasketItemUpdate::class, AfterBasketItemChanged::class);
        $dispatcher->listen(AfterBasketItemRemove::class, AfterBasketItemChanged::class);
        $dispatcher->listen(BeforeBasketItemAdd::class, BeforeBasketItemChanged::class);
        $dispatcher->listen(BeforeBasketItemUpdate::class, BeforeBasketItemChanged::class);
    }
}

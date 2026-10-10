<?php

namespace S39Staffel\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class S39StaffelRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router)
    {
        $router->get('s39staffel/ping', 'S39Staffel\Controllers\PingController@ping');
        $router->get('s39staffel/run', 'S39Staffel\Controllers\PingController@run');
    }
}

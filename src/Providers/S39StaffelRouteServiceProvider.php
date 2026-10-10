<?php

namespace S39Staffel\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class S39StaffelRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router, ApiRouter $api)
    {
        // Artikelbild-Proxy fuer die Druckvorschau (Canvas braucht ein Bild ohne CORS-Sperre).
        // Als REST-Route (/rest/s39staffel/img), damit sie nicht mit den Kategorie-Routen von IO kollidiert.
        $api->version(['v1'], ['namespace' => 'S39Staffel\Controllers'], function ($api) {
            $api->get('s39staffel/img', 'ImageController@show');
            $api->get('s39staffel/attach', 'OrderFileController@run');
        });
    }
}

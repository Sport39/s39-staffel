<?php

namespace S39Staffel\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class S39StaffelRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router)
    {
        // Artikelbild-Proxy fuer die Druckvorschau (Canvas braucht ein Bild ohne CORS-Sperre)
        $router->get('s39staffel/img', 'S39Staffel\Controllers\ImageController@show');
    }
}

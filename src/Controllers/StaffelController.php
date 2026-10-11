<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use S39Staffel\Services\StaffelService;

/**
 * Diagnose: /rest/s39staffel/staffel  (mit &run=1 wird die Neuberechnung ausgefuehrt)
 */
class StaffelController extends Controller
{
    public function run(Request $request, Response $response, StaffelService $service)
    {
        try {
            $diag = $service->diagnose();
            if ((string)$request->get('run', '') === '1') {
                $service->recalculate();
                $diag['after'] = $service->diagnose();
            }
            return $response->json($diag);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()], 500);
        }
    }
}

<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use S39Staffel\Services\StashService;

/**
 * POST /rest/s39staffel/stash  JSON {ref, name, type, data(base64)}
 * Legt eine Kopie der hochgeladenen Datei im Plugin-Speicher ab.
 */
class StashController extends Controller
{
    public function store(Request $request, Response $response, StashService $stash)
    {
        try {
            $ref = (string)$request->get('ref', '');
            $name = (string)$request->get('name', '');
            $type = substr((string)$request->get('type', ''), 0, 100);
            $data = (string)$request->get('data', '');
            $body = base64_decode($data, true);
            if ($body === false) {
                return $response->json(['ok' => false, 'error' => 'data ungueltig'], 400);
            }
            $err = $stash->put($ref, $name, $body, $type);
            if ($err !== '') {
                return $response->json(['ok' => false, 'error' => $err], 400);
            }
            return $response->json(['ok' => true, 'key' => StashService::key($ref, $name), 'bytes' => strlen($body)]);
        } catch (\Throwable $e) {
            return $response->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

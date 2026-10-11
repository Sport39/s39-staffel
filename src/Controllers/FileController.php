<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use S39Staffel\Services\StashService;

/**
 * Liefert eine vom Kunden im Teambestell-Konfigurator hochgeladene Originaldatei
 * (Logo, Vektordatei) aus dem Plugin-Speicher aus.
 *
 * Die Dateien haengen bewusst nicht mehr als Datei-Merkmal am Warenkorb
 * (jedes Datei-Merkmal macht jede Warenkorb-Aenderung ~5 s langsamer);
 * stattdessen steht der Link in der Druckliste.
 *
 * Aufruf: /rest/s39staffel/file?ref=<ordner>&name=<datei>
 */
class FileController extends Controller
{
    public function show(Request $request, Response $response, StashService $stash)
    {
        $ref = StashService::cleanRef((string)$request->get('ref', ''));
        $name = StashService::cleanName((string)$request->get('name', ''));
        if ($ref === '' || $name === '') {
            return $response->make('bad request', 400);
        }
        $hit = $stash->get($ref, $name);
        if (!$hit) {
            return $response->make('not found', 404);
        }
        $type = (string)$hit['type'];
        if ($type === '' || !preg_match('#^[a-z]+/[A-Za-z0-9.+-]+$#', $type)) {
            $type = 'application/octet-stream';
        }
        // Bilder/PDF duerfen im Browser angezeigt werden, alles andere nur als Download
        $inline = preg_match('#^(image/(jpeg|png|gif|webp)|application/pdf)$#', $type) === 1;
        return $response->make($hit['body'], 200, [
            'Content-Type' => $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

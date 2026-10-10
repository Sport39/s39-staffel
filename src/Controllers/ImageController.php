<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;

/**
 * Liefert ein Artikelbild vom Plenty-CDN unter der Shop-Domain aus.
 * Der Teambestell-Konfigurator zeichnet damit die Druckvorschau (PNG),
 * was direkt vom CDN wegen fehlender CORS-Freigabe nicht moeglich ist.
 *
 * Aufruf: /s39staffel/img?src=https://cdn02.plentyone.com/<bucket>/item/images/...
 */
class ImageController extends Controller
{
    public function show(Request $request, Response $response, LibraryCallContract $lib)
    {
        $src = (string)$request->get('src', '');

        // nur Artikelbilder des eigenen Plenty-CDN, nur harmlose Zeichen
        if (!preg_match('#^https://cdn[0-9]{2}\.plentyone\.com/[a-z0-9]+/item/images/[A-Za-z0-9_\-./]+\.(jpe?g|png|gif|webp)$#i', $src)) {
            return $response->make('forbidden', 403);
        }

        try {
            $r = $lib->call('S39Staffel::fetch_image', ['url' => $src]);
        } catch (\Throwable $e) {
            $r = null;
        }
        if (!is_array($r) || (int)($r['status'] ?? 0) !== 200 || empty($r['body'])) {
            return $response->make('not found', 404);
        }

        $type = (string)($r['type'] ?? '');
        if (!preg_match('#^image/#', $type)) {
            $type = 'image/jpeg';
        }

        return $response->make(base64_decode($r['body']), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}

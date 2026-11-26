<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use S39Staffel\Services\StashService;

/**
 * Druckangaben ohne Bestellmerkmale (Warenkorb bleibt schnell).
 * Die Druckpositionen liegen als normale Artikel im Warenkorb. Wofuer sie sind
 * (Artikel, Position, Text, Logo), steht in einer JSON-Datei im Plugin-Speicher:
 *   POST /rest/s39staffel/druck  {ref, entries:[{vid,name,artikel,pos,text,logo,qty}]}  -> ersetzt die Liste
 *   GET  /rest/s39staffel/druck?ref=...                                                 -> Liste lesen
 * Im Warenkorb traegt genau eine Druckposition das Merkmal "Druck fuer Artikel" mit "(Druck-Ref <ref>)".
 */
class DruckController extends Controller
{
    const NAME = 'druck.json';

    private function clean($v, int $max): string
    {
        return mb_substr(trim(strip_tags((string)$v)), 0, $max);
    }

    public function store(Request $request, Response $response, StashService $stash)
    {
        try {
            $ref = StashService::cleanRef((string)$request->get('ref', ''));
            if ($ref === '' || strlen($ref) < 8) {
                return $response->json(['ok' => false, 'error' => 'ref fehlt'], 400);
            }
            $in = $request->get('entries', []);
            if (!is_array($in)) {
                $in = [];
            }
            $out = [];
            foreach (array_slice($in, 0, 300) as $e) {
                if (!is_array($e) || (int)($e['vid'] ?? 0) <= 0) {
                    continue;
                }
                $out[] = [
                    'vid' => (int)$e['vid'],
                    'name' => $this->clean($e['name'] ?? '', 120),
                    'artikel' => $this->clean($e['artikel'] ?? '', 300),
                    'pos' => $this->clean($e['pos'] ?? '', 300),
                    'text' => $this->clean($e['text'] ?? '', 4000),
                    'logo' => $this->clean($e['logo'] ?? '', 300),
                    'qty' => max(0, (int)($e['qty'] ?? 0)),
                    'src' => $this->clean($e['src'] ?? '', 20),
                ];
            }
            $err = $stash->put($ref, self::NAME, json_encode(['v' => 1, 'at' => date('c'), 'entries' => $out]), 'application/json');
            if ($err !== '' && count($out)) {
                return $response->json(['ok' => false, 'error' => $err], 400);
            }
            return $response->json(['ok' => true, 'ref' => $ref, 'count' => count($out)]);
        } catch (\Throwable $e) {
            return $response->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, Response $response, StashService $stash)
    {
        $ref = StashService::cleanRef((string)$request->get('ref', ''));
        $r = $ref !== '' ? $stash->get($ref, self::NAME) : null;
        $data = $r ? json_decode($r['body'], true) : null;
        return $response->json(['ok' => true, 'ref' => $ref, 'entries' => is_array($data) ? ($data['entries'] ?? []) : []]);
    }

    /** Eintraege zu einer Ref (fuer Druckliste) */
    public static function load(string $ref): array
    {
        /** @var StashService $stash */
        $stash = pluginApp(StashService::class);
        $r = $stash->get($ref, self::NAME);
        $data = $r ? json_decode($r['body'], true) : null;
        return is_array($data) && is_array($data['entries'] ?? null) ? $data['entries'] : [];
    }
}

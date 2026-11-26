<?php

namespace S39Staffel\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Webshop\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;

/**
 * Info-Mail an Sport39, wenn ein Vereins-/Verbandskunde im Warenkorb ein Angebot (PDF) erstellt.
 * POST /rest/s39staffel/angebot  {nr, verein, ansprechpartner, notiz, summe, positionen:[{name,nummer,variante,menge,preis}]}
 * Nur fuer angemeldete Kunden der Kundenklassen 2 (Teamsport) und 3 (Verbandskunden).
 */
class AngebotController extends Controller
{
    use Loggable;

    const TO = 'online@sport39.de';

    private function t($v, int $max): string
    {
        $v = trim(strip_tags((string)$v));
        return htmlspecialchars(mb_substr($v, 0, $max), ENT_QUOTES, 'UTF-8');
    }

    public function notify(Request $request, Response $response, ContactRepositoryContract $contacts)
    {
        try {
            $c = $contacts->getContact();
            if (!($c instanceof Contact) || !in_array((int)$c->classId, [2, 3], true)) {
                return $response->json(['ok' => false, 'error' => 'not allowed'], 403);
            }
            $pos = $request->get('positionen', []);
            if (!is_array($pos)) {
                $pos = [];
            }
            $pos = array_slice($pos, 0, 200);
            $rows = '';
            foreach ($pos as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $rows .= '<tr><td>' . $this->t($p['name'] ?? '', 120) . '<br><small>' . $this->t($p['nummer'] ?? '', 40) . '</small></td><td>'
                    . $this->t($p['variante'] ?? '', 60) . '</td><td style="text-align:right">' . (int)($p['menge'] ?? 0)
                    . '</td><td style="text-align:right">' . $this->t($p['preis'] ?? '', 20) . '</td></tr>';
            }
            $nr = $this->t($request->get('nr', ''), 30);
            $verein = $this->t($request->get('verein', ''), 120);
            $ap = $this->t($request->get('ansprechpartner', ''), 120);
            $notiz = nl2br($this->t($request->get('notiz', ''), 1000));
            $summe = $this->t($request->get('summe', ''), 30);
            $name = $this->t(trim(($c->firstName ?? '') . ' ' . ($c->lastName ?? '')), 120);
            $mail = $this->t($c->email ?? '', 120);
            $kl = (int)$c->classId === 3 ? 'Verbandskunde' : 'Teamsport';

            $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#151515">'
                . '<p><b>Ein Kunde hat im Shop ein Angebot als PDF erstellt.</b> Es ist noch keine Bestellung. Gern nachfassen.</p>'
                . '<table cellpadding="4" style="border-collapse:collapse;font-size:14px">'
                . '<tr><td>Angebot</td><td><b>' . $nr . '</b> (gültig 14 Tage)</td></tr>'
                . '<tr><td>Verein / Firma</td><td><b>' . $verein . '</b></td></tr>'
                . '<tr><td>Ansprechpartner</td><td>' . $ap . '</td></tr>'
                . '<tr><td>Kundenkonto</td><td>' . $name . ' · ' . $mail . ' · Kontakt-ID ' . (int)$c->id . ' · ' . $kl . '</td></tr>'
                . '<tr><td>Summe</td><td><b>' . $summe . '</b> inkl. MwSt.</td></tr>'
                . ($notiz !== '' ? '<tr><td>Notiz</td><td>' . $notiz . '</td></tr>' : '')
                . '</table><br>'
                . '<table cellpadding="4" border="1" style="border-collapse:collapse;font-size:13px"><tr style="background:#f4f4f4"><th align="left">Artikel</th><th align="left">Größe / Variante</th><th>Menge</th><th>Einzelpreis</th></tr>'
                . $rows . '</table></div>';

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);
            $mailer->sendHtml($html, self::TO, 'Angebot ' . $nr . ' erstellt: ' . html_entity_decode($verein, ENT_QUOTES, 'UTF-8') . ' (' . html_entity_decode($summe, ENT_QUOTES, 'UTF-8') . ')', [], [], $c->email ?: null);
            return $response->json(['ok' => true]);
        } catch (\Throwable $e) {
            try {
                $this->getLogger('S39Staffel')->error('S39Staffel::log.error', ['where' => 'angebot mail', 'message' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
            } catch (\Throwable $x) {
            }
            return $response->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

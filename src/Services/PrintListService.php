<?php

namespace S39Staffel\Services;

use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Webshop\Contracts\WebstoreConfigurationRepositoryContract;

/**
 * Druckliste: fasst die Personalisierung eines Auftrags (Druckelemente,
 * Namen/Nummern je Stueck) als Tabelle zusammen und erzeugt daraus ein PDF.
 *
 * Datenquelle sind die Auftragsmerkmale (Positionen vom Typ 15 mit
 * Merkmal-ID (typeId 81) und Wert (typeId 82)), die das Team-Script
 * beim Bestellen schreibt.
 */
class PrintListService
{
    const P_ARTIKEL = 127;
    const P_POSITION = 128;
    const P_TEXT = 129;
    const P_LOGO = 130;

    /** Druck-Varianten, bei denen je Stueck ein Wert eingegeben wird */
    const EACH = [61104 => 'Spielername', 61105 => 'Rückennummer', 17325 => 'Initialen / Nr.'];
    /** Druckvorbereitung (keine Druckposition) */
    const SKIP = [234564];

    /**
     * Struktur:
     * [
     *   'articles' => [['name'=>..,'attrs'=>..,'qty'=>..], ...],
     *   'groups' => [ key => ['title'=>..,'elements'=>[[type,position,detail]], 'each'=>[vid=>[[size,value],..]], 'sizes'=>[..]] ]
     * ]
     */
    public function parse($order): array
    {
        $articles = [];
        $groups = [];
        if (!($order instanceof Order)) {
            return ['articles' => [], 'groups' => []];
        }
        $items = [];
        foreach ($order->orderItems as $it) {
            $typeId = (int)$it->typeId;
            if ($typeId === 15) {
                if (!count($items)) {
                    continue;
                }
                $pid = 0;
                $val = '';
                foreach ($it->properties as $p) {
                    if ((int)$p->typeId === 81) {
                        $pid = (int)$p->value;
                    } elseif ((int)$p->typeId === 82) {
                        $val = (string)$p->value;
                    }
                }
                if ($pid) {
                    $items[count($items) - 1]['props'][$pid] = $val;
                }
                continue;
            }
            if ($typeId !== 1 && $typeId !== 2 && $typeId !== 3) {
                continue; // Versand, Rabatt usw.
            }
            $items[] = [
                'vid' => (int)$it->itemVariationId,
                'name' => (string)$it->orderItemName,
                'attrs' => (string)$it->attributeValues,
                'qty' => (float)$it->quantity,
                'props' => [],
            ];
        }
        foreach ($items as $it) {
            $art = isset($it['props'][self::P_ARTIKEL]) ? $it['props'][self::P_ARTIKEL] : '';
            if ($art === '') {
                $articles[] = $it;
                continue;
            }
            if (in_array($it['vid'], self::SKIP, true)) {
                continue;
            }
            $art = preg_replace('/\s*\(Ref [^)]*\)/', '', $art);
            $parts = preg_split('/\s+\x{2013}\s+/u', $art, 2); // "Artikel – 2× 122/128, 3× 134/140"
            $key = trim($parts[0]);
            if (!isset($groups[$key])) {
                $groups[$key] = ['title' => $key, 'sizes' => '', 'elements' => [], 'each' => []];
            }
            if (isset($parts[1]) && $groups[$key]['sizes'] === '') {
                $groups[$key]['sizes'] = trim($parts[1]);
            }
            $pos = isset($it['props'][self::P_POSITION]) ? $it['props'][self::P_POSITION] : '';
            $text = isset($it['props'][self::P_TEXT]) ? $it['props'][self::P_TEXT] : '';
            $logo = isset($it['props'][self::P_LOGO]) ? preg_replace('/\s*\(Ref [^)]*\)/', '', $it['props'][self::P_LOGO]) : '';
            if (isset(self::EACH[$it['vid']])) {
                foreach (explode(' | ', $text) as $entry) {
                    $entry = trim($entry);
                    if ($entry === '') {
                        continue;
                    }
                    $sv = explode(': ', $entry, 2);
                    $groups[$key]['each'][$it['vid']][] = [trim($sv[0]), isset($sv[1]) ? trim($sv[1]) : ''];
                }
                $ek = 'e' . $it['vid'];
                $prev = isset($groups[$key]['elements'][$ek]) ? (int)$groups[$key]['elements'][$ek][3] : 0;
                $groups[$key]['elements'][$ek] = [$it['name'], $pos, 'je Stück – siehe Liste unten', $prev + (int)$it['qty']];
                continue;
            }
            $detail = $text !== '' ? $text : $logo;
            $groups[$key]['elements'][] = [$it['name'], $pos, $detail, (int)$it['qty']];
        }
        return ['articles' => $articles, 'groups' => array_values($groups)];
    }

    /** @var PdfWriter */
    private $pdf;
    public $y = 0;
    private $pageNo = 0;
    private $orderId = 0;
    private $date = '';

    private function newPage()
    {
        $this->pdf->addPage();
        $this->pageNo++;
        $this->pdf->text(40, 50, 'Druckliste Auftrag ' . $this->orderId, 16, true);
        $this->pdf->text(PdfWriter::W - 190, 50, 'Bestellt am ' . $this->date . '  ·  Seite ' . $this->pageNo, 9, false, .4);
        $this->pdf->line(40, 58, PdfWriter::W - 40, 58, .8);
        $this->y = 80;
    }

    private function need(float $h)
    {
        if ($this->y + $h > PdfWriter::H - 50) {
            $this->newPage();
        }
    }

    /** PDF-Inhalt oder '' wenn der Auftrag keine Druckdaten hat */
    public function build($order, array $images = [], array $links = []): string
    {
        $data = $this->parse($order);
        if (!count($data['groups'])) {
            return '';
        }
        /** @var PdfWriter $pdf */
        $pdf = pluginApp(PdfWriter::class);
        $this->pdf = $pdf;
        $L = 40;
        $R = PdfWriter::W - 40;
        $this->pageNo = 0;
        $this->orderId = (int)$order->id;
        $this->date = date('d.m.Y', strtotime((string)$order->createdAt) ?: time());
        $this->newPage();

        // Bestellte Artikel
        if (count($data['articles'])) {
            $pdf->text($L, $this->y, 'Bestellte Artikel', 11, true);
            $this->y += 16;
            foreach ($data['articles'] as $a) {
                $this->need(14);
                $q = $a['qty'] == floor($a['qty']) ? (string)(int)$a['qty'] : (string)$a['qty'];
                $line = $q . ' × ' . $a['name'] . ($a['attrs'] !== '' ? ' (' . $a['attrs'] . ')' : '');
                $pdf->text($L, $this->y, $line, 9.5);
                $this->y += 13;
            }
            $this->y += 10;
        }

        foreach ($data['groups'] as $g) {
            $this->need(60);
            $pdf->rect($L, $this->y - 11, $R - $L, 18, .9);
            $pdf->text($L + 5, $this->y + 2, 'Druck für: ' . $g['title'], 11, true);
            $this->y += 22;
            if ($g['sizes'] !== '') {
                $pdf->text($L, $this->y, 'Größen: ' . $g['sizes'], 9, false, .3);
                $this->y += 14;
            }
            // Tabelle Druckelemente
            $cols = [['Druckart', 130], ['Position / Schrift / Farbe / Größe', 190], ['Text / Datei', 160], ['Stück', 35]];
            $this->table($pdf, $L, $cols, array_map(function ($e) {
                return [(string)$e[0], (string)$e[1], (string)$e[2], (string)$e[3]];
            }, array_values($g['elements'])));
            $this->y += 12;
            // Tabelle Namen / Nummern je Stueck
            if (count($g['each'])) {
                $vids = array_keys($g['each']);
                $rows = [];
                $max = 0;
                foreach ($vids as $v) {
                    $max = max($max, count($g['each'][$v]));
                }
                for ($i = 0; $i < $max; $i++) {
                    $size = '';
                    $row = [(string)($i + 1)];
                    foreach ($vids as $v) {
                        $e = isset($g['each'][$v][$i]) ? $g['each'][$v][$i] : ['', ''];
                        if ($size === '' && $e[0] !== '') {
                            $size = $e[0];
                        }
                    }
                    $row[] = $size;
                    foreach ($vids as $v) {
                        $row[] = isset($g['each'][$v][$i]) ? $g['each'][$v][$i][1] : '';
                    }
                    $rows[] = $row;
                }
                $this->need(40);
                $pdf->text($L, $this->y, 'Namen / Nummern je Stück', 10, true);
                $this->y += 14;
                $cols = [['#', 25], ['Größe', 60]];
                $rest = ($R - $L - 85) / max(1, count($vids));
                foreach ($vids as $v) {
                    $cols[] = [self::EACH[$v], $rest];
                }
                $this->table($pdf, $L, $cols, $rows);
                $this->y += 12;
            }
            $this->y += 8;
        }
        // Links zu den Originaldateien (liegen im Plugin-Speicher, nicht am Warenkorb)
        if (count($links)) {
            $this->need(30 + 13 * count($links));
            $pdf->text($L, $this->y, 'Hochgeladene Originaldateien', 10, true);
            $this->y += 14;
            foreach ($links as $name => $url) {
                $pdf->text($L, $this->y, (string)$name, 9);
                $this->y += 11;
                $pdf->text($L + 12, $this->y, (string)$url, 7.5, false, .35);
                $this->y += 13;
            }
            $this->y += 6;
        }
        $this->need(30);
        $pdf->text($L, $this->y + 6, 'Automatisch erstellt aus den Bestelldaten. Platzierung und Größen werden vor dem Druck abgestimmt.', 8, false, .45);
        // Bildseiten: Druckvorschauen und Logo
        foreach ($images as $img) {
            $body = (string)($img['body'] ?? '');
            $name = '';
            if (substr($body, 0, 3) === "\xFF\xD8\xFF") {
                $name = $pdf->addJpeg($body);
            } elseif (substr($body, 0, 4) === "\x89PNG") {
                $name = $pdf->addPng($body);
            }
            if ($name === '') {
                continue;
            }
            $this->newPage();
            $pdf->text($L, $this->y, (string)($img['title'] ?? ''), 12, true);
            $this->y += 8;
            if (!empty($img['sub'])) {
                $this->y += 12;
                $pdf->text($L, $this->y, (string)$img['sub'], 9, false, .4);
            }
            $pdf->image($name, $L, $this->y + 14, $R - $L, PdfWriter::H - 50 - ($this->y + 14));
        }
        return $pdf->output();
    }

    /**
     * Hochgeladene Dateien des Auftrags einsammeln:
     * ['images' => [[title, sub, body], ...] (Vorschauen + Bild-Logos fuer die Druckliste),
     *  'pdfs'   => [name => body] (PDF-Uploads, werden als eigenes Dokument angehaengt),
     *  'skipped'=> [name, ...]]
     */
    public function collect($order): array
    {
        /** @var OrderFileService $files */
        $files = pluginApp(OrderFileService::class);
        $orderId = (int)$order->id;
        $images = [];
        $pdfs = [];
        $skipped = [];
        $links = [];
        foreach ($files->refFiles($order) as $key => $name) {
            $r = $files->read((string)$key);
            if (!$r || $r['body'] === '') {
                continue;
            }
            $body = $r['body'];
            $base = preg_replace('/\.[A-Za-z0-9]+$/', '', (string)$name);
            $url = $this->fileUrl((string)$key);
            if (preg_match('/^Druckvorschau_(Vorderseite|Rueckseite|Hose)/', (string)$name, $m)) {
                $images[] = ['title' => 'Druckvorschau ' . str_replace('Rueckseite', 'Rückseite', $m[1]), 'sub' => 'Digitale Vorschau – unverbindlich, Platzierung wird vor dem Druck abgestimmt', 'body' => $body, 'order' => 0];
            } elseif (substr($body, 0, 4) === '%PDF') {
                $pdfs[$base . '_' . $orderId] = $body;
                $links[(string)$name] = $url;
            } elseif (substr($body, 0, 3) === "\xFF\xD8\xFF" || substr($body, 0, 4) === "\x89PNG") {
                $images[] = ['title' => 'Logo-Datei: ' . $name, 'sub' => 'Originaldatei: ' . $url, 'body' => $body, 'order' => 1];
                $links[(string)$name] = $url;
            } else {
                $skipped[] = (string)$name;
                $links[(string)$name] = $url;
            }
        }
        usort($images, function ($a, $b) {
            return $a['order'] - $b['order'];
        });
        return ['images' => $images, 'pdfs' => $pdfs, 'skipped' => $skipped, 'links' => $links];
    }

    /** Download-Link einer Originaldatei (Plugin-Speicher) fuer die Druckliste */
    private function fileUrl(string $key): string
    {
        $slash = strpos($key, '/');
        $ref = $slash === false ? '' : substr($key, 0, $slash);
        $name = $slash === false ? $key : substr($key, $slash + 1);
        $host = 'https://www.sport39.de';
        try {
            /** @var WebstoreConfigurationRepositoryContract $ws */
            $ws = pluginApp(WebstoreConfigurationRepositoryContract::class);
            $cfg = $ws->getWebstoreConfiguration();
            $d = (string)($cfg->domainSsl ?: $cfg->domain);
            if ($d !== '') {
                $host = preg_match('#^https?://#', $d) ? rtrim($d, '/') : 'https://' . rtrim($d, '/');
            }
        } catch (\Throwable $e) {
        }
        return $host . '/rest/s39staffel/file?ref=' . rawurlencode($ref) . '&name=' . rawurlencode($name);
    }

    /** PDF als Auftragsdokument (Externes Dokument) "Druckliste_<id>.pdf" ablegen */
    public function attach(int $orderId, string $pdf, string $name = ''): bool
    {
        if ($orderId <= 0 || $pdf === '') {
            return false;
        }
        /** @var DocumentRepositoryContract $docRepo */
        $docRepo = pluginApp(DocumentRepositoryContract::class);
        // Plenty speichert externe Dokumente immer als "<Name>.pdf" – daher ohne eigene Endung
        $name = $name !== '' ? $name : 'Druckliste_' . $orderId;
        $docRepo->uploadOrderDocuments($orderId, 'uploaded', ['documents' => [[
            'content' => base64_encode($pdf),
            'numberWithPrefix' => $name,
            'numberWithoutPrefix' => $name,
        ]]]);
        return true;
    }

    /**
     * Einfache Tabelle mit Umbruch in den Zellen; setzt $this->y weiter.
     * $cols: [[Titel, Breite], ...]
     */
    private function table(PdfWriter $pdf, float $x0, array $cols, array $rows)
    {
        $fs = 9;
        $lh = 11;
        $pad = 3;
        $self = $this;
        $header = function () use ($pdf, $x0, $self, $cols, $fs, $pad) {
            $y = $self->y;
            $w = 0;
            foreach ($cols as $c) {
                $w += $c[1];
            }
            $pdf->rect($x0, $y - 9, $w, 14, .85);
            $x = $x0;
            foreach ($cols as $c) {
                $pdf->text($x + $pad, $y + 1, $c[0], $fs, true);
                $x += $c[1];
            }
            $self->y = $y + 14;
        };
        $header();
        $y = $this->y;
        $stripe = false;
        foreach ($rows as $r) {
            $lines = [];
            $n = 1;
            foreach ($cols as $i => $c) {
                $lines[$i] = $pdf->wrap(isset($r[$i]) ? (string)$r[$i] : '', $c[1] - 2 * $pad, $fs);
                $n = max($n, count($lines[$i]));
            }
            $h = $n * $lh + 4;
            if ($this->y + $h > PdfWriter::H - 50) {
                $this->newPage();
                $header();
            }
            $y = $this->y;
            $w = 0;
            foreach ($cols as $c) {
                $w += $c[1];
            }
            if ($stripe) {
                $pdf->rect($x0, $y - 9, $w, $h, .96);
            }
            $stripe = !$stripe;
            $x = $x0;
            foreach ($cols as $i => $c) {
                $yy = $y + 1;
                foreach ($lines[$i] as $ln) {
                    $pdf->text($x + $pad, $yy, $ln, $fs);
                    $yy += $lh;
                }
                $x += $c[1];
            }
            $y += $h;
            $pdf->line($x0, $y - 9, $x0 + $w, $y - 9, .3, .7);
            $this->y = $y;
        }
    }
}

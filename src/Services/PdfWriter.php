<?php

namespace S39Staffel\Services;

/**
 * Sehr kleiner PDF-Schreiber (A4, Helvetica, Text, Linien, Rechtecke).
 * Reicht fuer die Druckliste; kommt ohne externe Bibliothek aus.
 * Koordinaten in Punkt, Ursprung oben links (wird intern umgerechnet).
 */
class PdfWriter
{
    const W = 595.28;
    const H = 841.89;

    /** @var array Seiten-Inhaltsstroeme */
    private $pages = [];
    /** @var string aktueller Inhaltsstrom */
    private $cur = '';

    public function addPage()
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = ' ';
    }

    /** Text an Position (x,y = linke Grundlinie), size in pt, bold, Farbe grau 0..1 */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, float $gray = 0)
    {
        $font = $bold ? '/F2' : '/F1';
        $this->cur .= sprintf("BT %s %.2f Tf %.3f g %.2f %.2f Td (%s) Tj ET\n", $font, $size, $gray, $x, self::H - $y, $this->esc($s));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = .5, float $gray = 0)
    {
        $this->cur .= sprintf("%.2f w %.3f G %.2f %.2f m %.2f %.2f l S\n", $width, $gray, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, float $gray = .93)
    {
        $this->cur .= sprintf("%.3f g %.2f %.2f %.2f %.2f re f 0 g\n", $gray, $x, self::H - $y - $h, $w, $h);
    }

    /** ungefaehre Textbreite (Helvetica) */
    public function width(string $s, float $size, bool $bold = false): float
    {
        $n = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (($ord = ord($c)) >= 0x80 && $ord < 0xC0) {
                continue; // UTF-8-Folgebyte
            }
            if (strpos('iljtfI.,:;\'|!', $c) !== false) {
                $n += .3;
            } elseif (strpos('mwMW', $c) !== false) {
                $n += .85;
            } elseif ($c >= 'A' && $c <= 'Z') {
                $n += .68;
            } elseif ($c >= '0' && $c <= '9') {
                $n += .56;
            } elseif ($c === ' ') {
                $n += .28;
            } else {
                $n += .54;
            }
        }
        return $n * $size * ($bold ? 1.06 : 1);
    }

    /** Text auf Zeilen umbrechen, die in $maxW passen */
    public function wrap(string $s, float $maxW, float $size, bool $bold = false): array
    {
        $out = [];
        foreach (explode("\n", $s) as $para) {
            $words = preg_split('/\s+/', trim($para));
            $line = '';
            foreach ($words as $w) {
                if ($w === '') {
                    continue;
                }
                $try = $line === '' ? $w : $line . ' ' . $w;
                if ($this->width($try, $size, $bold) <= $maxW) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                }
                // einzelnes Wort zu lang: hart trennen
                while ($this->width($w, $size, $bold) > $maxW && strlen($w) > 1) {
                    $cut = max(1, (int)floor(strlen($w) * $maxW / $this->width($w, $size, $bold)) - 1);
                    while ($cut > 1 && (ord($w[$cut]) & 0xC0) === 0x80) {
                        $cut--;
                    }
                    $out[] = substr($w, 0, $cut);
                    $w = substr($w, $cut);
                }
                $line = $w;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Fertiges PDF als String */
    public function output(): string
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
            $this->cur = '';
        }
        if (!count($this->pages)) {
            $this->pages[] = ' ';
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $pageNo = $n++;
            $contNo = $n++;
            $kids[] = $pageNo . ' 0 R';
            $objs[$pageNo] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contNo);
            $objs[$contNo] = '<< /Length ' . strlen($content) . ' >>' . "\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $no => $body) {
            $offsets[$no] = strlen($pdf);
            $pdf .= $no . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    /** UTF-8 -> WinAnsi (cp1252) und PDF-Escaping */
    private function esc(string $s): string
    {
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $cp = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cp = 0x3F;
                $i += 4;
            } else {
                $cp = 0x3F;
                $i++;
            }
            $map = [0x20AC => 0x80, 0x201A => 0x82, 0x201E => 0x84, 0x2026 => 0x85, 0x2013 => 0x96, 0x2014 => 0x97,
                0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2122 => 0x99, 0x00D7 => 0xD7];
            if (isset($map[$cp])) {
                $cp = $map[$cp];
            } elseif ($cp > 0xFF) {
                $cp = 0x3F;
            }
            $c = chr($cp);
            if ($c === '(' || $c === ')' || $c === '\\') {
                $out .= '\\' . $c;
            } elseif ($cp < 0x20) {
                $out .= ' ';
            } else {
                $out .= $c;
            }
        }
        return $out;
    }
}

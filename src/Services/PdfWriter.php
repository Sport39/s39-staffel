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
    /** @var array eingebettete Bilder: name => [w,h,dict,data,smask] */
    private $images = [];

    /**
     * JPEG einbetten. Gibt den Ressourcennamen (/ImN) zurueck oder '' wenn nicht lesbar.
     */
    public function addJpeg(string $data): string
    {
        $info = $this->jpegInfo($data);
        if (!$info) {
            return '';
        }
        $cs = $info['c'] === 1 ? '/DeviceGray' : ($info['c'] === 4 ? '/DeviceCMYK' : '/DeviceRGB');
        $dict = '/Filter /DCTDecode /ColorSpace ' . $cs . ' /BitsPerComponent 8';
        if ($info['c'] === 4) {
            $dict .= ' /Decode [1 0 1 0 1 0 1 0]';
        }
        return $this->storeImage($info['w'], $info['h'], $dict, $data, null);
    }

    /**
     * PNG einbetten (8 Bit, nicht interlaced). Grau/RGB/Palette werden roh uebernommen,
     * Bilder mit Alpha-Kanal werden entpackt und als Bild + SMask abgelegt.
     */
    public function addPng(string $data): string
    {
        if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return '';
        }
        $pos = 8;
        $len = strlen($data);
        $w = $h = $bd = $ct = $il = 0;
        $plte = '';
        $idat = '';
        while ($pos + 8 <= $len) {
            $clen = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $body = substr($data, $pos + 8, $clen);
            if ($type === 'IHDR') {
                $u = unpack('Nw/Nh/Cbd/Cct/Ccm/Cfl/Cil', $body);
                $w = $u['w'];
                $h = $u['h'];
                $bd = $u['bd'];
                $ct = $u['ct'];
                $il = $u['il'];
            } elseif ($type === 'PLTE') {
                $plte = $body;
            } elseif ($type === 'IDAT') {
                $idat .= $body;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $clen;
        }
        if ($w <= 0 || $h <= 0 || $il !== 0 || $idat === '' || $bd > 8) {
            return '';
        }
        if ($ct === 0 || $ct === 2 || $ct === 3) {
            $colors = $ct === 2 ? 3 : 1;
            if ($ct === 0) {
                $cs = '/DeviceGray';
            } elseif ($ct === 2) {
                $cs = '/DeviceRGB';
            } else {
                $cs = '[/Indexed /DeviceRGB ' . (max(1, (int)(strlen($plte) / 3)) - 1) . ' <' . bin2hex($plte) . '>]';
            }
            $dict = '/Filter /FlateDecode /ColorSpace ' . $cs . ' /BitsPerComponent ' . $bd
                . ' /DecodeParms << /Predictor 15 /Colors ' . $colors . ' /BitsPerComponent ' . $bd . ' /Columns ' . $w . ' >>';
            return $this->storeImage($w, $h, $dict, $idat, null);
        }
        if (($ct !== 4 && $ct !== 6) || $bd !== 8 || $w * $h > 4000000) {
            return '';
        }
        // Alpha-Kanal abtrennen
        $raw = @gzuncompress($idat);
        if ($raw === false) {
            return '';
        }
        $ch = $ct === 6 ? 4 : 2;
        $stride = $w * $ch;
        $color = '';
        $alpha = '';
        $prev = array_fill(0, $stride, 0);
        $off = 0;
        for ($y = 0; $y < $h; $y++) {
            $ft = ord($raw[$off]);
            $row = array_values(unpack('C*', substr($raw, $off + 1, $stride)));
            $off += 1 + $stride;
            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= $ch ? $row[$i - $ch] : 0;
                $b = $prev[$i];
                $c = $i >= $ch ? $prev[$i - $ch] : 0;
                $x = $row[$i];
                if ($ft === 1) {
                    $x += $a;
                } elseif ($ft === 2) {
                    $x += $b;
                } elseif ($ft === 3) {
                    $x += (int)(($a + $b) / 2);
                } elseif ($ft === 4) {
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                }
                $row[$i] = $x & 255;
            }
            $cl = '';
            $al = '';
            for ($i = 0; $i < $stride; $i += $ch) {
                if ($ch === 4) {
                    $cl .= chr($row[$i]) . chr($row[$i + 1]) . chr($row[$i + 2]);
                    $al .= chr($row[$i + 3]);
                } else {
                    $cl .= chr($row[$i]);
                    $al .= chr($row[$i + 1]);
                }
            }
            $color .= $cl;
            $alpha .= $al;
            $prev = $row;
        }
        $cs = $ch === 4 ? '/DeviceRGB' : '/DeviceGray';
        $dict = '/Filter /FlateDecode /ColorSpace ' . $cs . ' /BitsPerComponent 8';
        return $this->storeImage($w, $h, $dict, gzcompress($color, 6), gzcompress($alpha, 6));
    }

    /** Bild in den Rahmen (x,y oben links, maxW x maxH) einpassen, zentriert */
    public function image(string $name, float $x, float $y, float $maxW, float $maxH)
    {
        if (!isset($this->images[$name])) {
            return;
        }
        $im = $this->images[$name];
        $r = min($maxW / $im['w'], $maxH / $im['h']);
        $w = $im['w'] * $r;
        $h = $im['h'] * $r;
        $px = $x + ($maxW - $w) / 2;
        $py = self::H - $y - ($maxH - $h) / 2 - $h;
        $this->cur .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $w, $h, $px, $py, $name);
    }

    public function hasImage(string $name): bool
    {
        return $name !== '' && isset($this->images[$name]);
    }

    private function storeImage(int $w, int $h, string $dict, string $data, $smask): string
    {
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = ['w' => $w, 'h' => $h, 'dict' => $dict, 'data' => $data, 'smask' => $smask];
        return $name;
    }

    /** Breite/Hoehe/Komponenten aus dem JPEG-Header */
    private function jpegInfo(string $d)
    {
        if (strlen($d) < 4 || ord($d[0]) !== 0xFF || ord($d[1]) !== 0xD8) {
            return null;
        }
        $p = 2;
        $n = strlen($d);
        while ($p + 9 < $n) {
            if (ord($d[$p]) !== 0xFF) {
                $p++;
                continue;
            }
            $m = ord($d[$p + 1]);
            if ($m === 0xD8 || ($m >= 0xD0 && $m <= 0xD7) || $m === 0x01 || $m === 0xFF) {
                $p += 2;
                continue;
            }
            $l = (ord($d[$p + 2]) << 8) | ord($d[$p + 3]);
            if (in_array($m, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                return ['h' => (ord($d[$p + 5]) << 8) | ord($d[$p + 6]), 'w' => (ord($d[$p + 7]) << 8) | ord($d[$p + 8]), 'c' => ord($d[$p + 9])];
            }
            $p += 2 + $l;
        }
        return null;
    }

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
        $xobj = '';
        foreach ($this->images as $name => $im) {
            $smaskRef = '';
            if ($im['smask'] !== null) {
                $sNo = $n++;
                $objs[$sNo] = '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h']
                    . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($im['smask']) . ' >>'
                    . "\nstream\n" . $im['smask'] . "\nendstream";
                $smaskRef = ' /SMask ' . $sNo . ' 0 R';
            }
            $iNo = $n++;
            $objs[$iNo] = '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' ' . $im['dict']
                . $smaskRef . ' /Length ' . strlen($im['data']) . ' >>' . "\nstream\n" . $im['data'] . "\nendstream";
            $xobj .= ' /' . $name . ' ' . $iNo . ' 0 R';
        }
        $res = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj !== '' ? ' /XObject <<' . $xobj . ' >>' : '');
        foreach ($this->pages as $content) {
            $pageNo = $n++;
            $contNo = $n++;
            $kids[] = $pageNo . ' 0 R';
            $objs[$pageNo] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << %s >> /Contents %d 0 R >>', self::W, self::H, $res, $contNo);
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

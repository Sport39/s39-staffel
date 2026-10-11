<?php
/**
 * Trennt bei einem PNG mit Alpha-Kanal (Farbtyp 4/6, 8 Bit, nicht interlaced)
 * Farbdaten und Alphakanal und liefert beide zlib-komprimiert (base64) zurueck,
 * passend fuer ein PDF-Bildobjekt (/FlateDecode) mit /SMask.
 * Aufruf ueber LibraryCallContract, Parameter: png (base64).
 */
$data = base64_decode((string)SdkRestApi::getParam('png', ''));
if ($data === '' || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    return ['ok' => false, 'error' => 'kein PNG'];
}
$pos = 8;
$len = strlen($data);
$w = $h = $bd = $ct = $il = 0;
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
    } elseif ($type === 'IDAT') {
        $idat .= $body;
    } elseif ($type === 'IEND') {
        break;
    }
    $pos += 12 + $clen;
}
if ($w <= 0 || $h <= 0 || $il !== 0 || $bd !== 8 || ($ct !== 4 && $ct !== 6) || $w * $h > 4000000) {
    return ['ok' => false, 'error' => 'nicht unterstuetzt', 'w' => $w, 'h' => $h, 'ct' => $ct, 'bd' => $bd, 'il' => $il];
}
$raw = @gzuncompress($idat);
if ($raw === false) {
    return ['ok' => false, 'error' => 'zlib'];
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
return [
    'ok' => true,
    'w' => $w,
    'h' => $h,
    'ct' => $ct,
    'color' => base64_encode(gzcompress($color, 6)),
    'alpha' => base64_encode(gzcompress($alpha, 6)),
];

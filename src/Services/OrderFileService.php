<?php

namespace S39Staffel\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Frontend\Services\OrderPropertyFileService;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;

/**
 * Kopiert Dateien aus Auftragsmerkmalen (Typ "Datei", z. B. hochgeladene
 * Vereinslogos) in die Auftragsdokumente des Auftrags (Typ "uploaded" =
 * Externe Dokumente). Diese koennen in E-Mail-Vorlagen unter
 * "Dynamische Anhaenge > Externe Dokumente" mitgesendet werden.
 */
class OrderFileService
{
    use Loggable;

    /** @var ConfigRepository */
    private $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    public function active(): bool
    {
        $v = $this->config->get('S39Staffel.settings.attachFiles');
        if ($v === null || $v === '') {
            $v = $this->config->get('S39Staffel.attachFiles', '1');
        }
        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    private function verbose(): bool
    {
        $v = $this->config->get('S39Staffel.settings.log');
        if ($v === null || $v === '') {
            $v = $this->config->get('S39Staffel.log', '0');
        }
        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    /** Hinweis ins Log schreiben (unabhaengig vom Ausfuehrlich-Schalter) */
    public function note(string $key, array $data)
    {
        try {
            $this->getLogger('S39Staffel')->info('S39Staffel::log.' . $key, $data);
        } catch (\Throwable $x) {
        }
    }

    public function logError(string $where, \Throwable $e)
    {
        try {
            $this->getLogger('S39Staffel')->error('S39Staffel::log.error', [
                'where' => $where,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (\Throwable $x) {
        }
    }

    /**
     * Alle Datei-Merkmale des Auftrags in die Auftragsdokumente kopieren.
     *
     * Plenty legt Auftragsmerkmale als eigene Positionen (Typ 15) an. Der Wert
     * steht in den Positionseigenschaften (typeId 82); bei Datei-Merkmalen ist
     * das der Speicherpfad "orderPropertyFiles/<auftrag>/<position>/propFile...".
     */
    public function copyPropertyFiles($order): array
    {
        if (!($order instanceof Order) || (int)$order->id <= 0) {
            return [];
        }
        $files = [];
        $names = [];
        foreach ($order->orderItems as $item) {
            $value = '';
            foreach ($item->properties as $p) {
                if ((int)$p->typeId === 82) {
                    $value = (string)$p->value;
                }
            }
            if ($value === '') {
                continue;
            }
            if (strpos($value, 'orderPropertyFiles/') === 0) {
                $files[$value] = (string)$item->orderItemName;
            } elseif (strpos($value, 'hochgeladen: ') === 0) {
                $names[] = trim(substr($value, 13));
            }
        }
        $this->note('files', ['orderId' => $order->id, 'items' => count($order->orderItems), 'found' => array_keys($files)]);
        if (!count($files)) {
            return [];
        }

        /** @var OrderPropertyFileService $fileService */
        $fileService = pluginApp(OrderPropertyFileService::class);
        /** @var DocumentRepositoryContract $docRepo */
        $docRepo = pluginApp(DocumentRepositoryContract::class);

        $docs = [];
        $done = [];
        $n = 0;
        foreach ($files as $key => $label) {
            try {
                $obj = $this->fetch($fileService, $key);
                $body = $obj ? $obj['body'] : null;
                if ($body === null || $body === '') {
                    $this->log('files', ['orderId' => $order->id, 'key' => $key, 'result' => 'leer']);
                    continue;
                }
                $name = isset($names[$n]) ? $names[$n] : '';
                if ($name === '') {
                    $ext = $this->extension($obj);
                    $name = 'Datei-' . ($n + 1) . ($label !== '' ? '-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $label) : '') . $ext;
                }
                $n++;
                $docs[] = [
                    'content' => base64_encode($body),
                    'numberWithPrefix' => $name,
                    'numberWithoutPrefix' => $name,
                ];
                $done[] = $name;
            } catch (\Throwable $e) {
                $this->logError('getFile ' . $key, $e);
            }
        }
        if (!count($docs)) {
            return [];
        }
        $result = $docRepo->uploadOrderDocuments((int)$order->id, 'uploaded', ['documents' => $docs]);
        $this->note('files', [
            'orderId' => $order->id,
            'files' => $done,
            'result' => is_array($result) ? count($result) : 'n/a',
        ]);
        return $done;
    }

    /** @var array Diagnose der letzten Dateizugriffe (fuer /attach?debug=1) */
    public $diag = [];

    private function fetch(OrderPropertyFileService $fileService, string $key)
    {
        $short = strpos($key, 'orderPropertyFiles/') === 0 ? substr($key, 19) : $key;
        foreach ([$key, $short] as $k) {
            try {
                $obj = $fileService->getFile($k);
                $len = ($obj && isset($obj->body)) ? strlen((string)$obj->body) : -1;
                $this->diag[] = 'getFile ' . $k . ' => ' . ($obj ? get_class($obj) : 'null') . ' body ' . $len;
                if ($obj && isset($obj->body) && $obj->body !== '') {
                    $ct = '';
                    try {
                        $ct = (string)($obj->metaData['headers']['content-type'] ?? '');
                    } catch (\Throwable $x) {
                    }
                    return ['body' => (string)$obj->body, 'type' => $ct];
                }
            } catch (\Throwable $e) {
                $this->diag[] = 'getFile ' . $k . ' => EXC ' . $e->getMessage();
            }
        }
        // Fallback: Download-URL ermitteln und serverseitig (lib) laden
        foreach ([$key, $short] as $k) {
            try {
                $url = (string)$fileService->getFileURL($k);
                $this->diag[] = 'getFileURL ' . $k . ' => ' . substr($url, 0, 120);
                if (strpos($url, 'http') !== 0) {
                    continue;
                }
                /** @var DownloadService $dl */
                $dl = pluginApp(DownloadService::class);
                $file = $dl->get($url);
                $this->diag[] = 'download ' . $k . ' => ' . ($file ? 'ok ' . strlen($file['body']) . ' ' . $file['type'] : 'failed');
                if ($file) {
                    return $file;
                }
            } catch (\Throwable $e) {
                $this->diag[] = 'getFileURL ' . $k . ' => EXC ' . $e->getMessage();
            }
        }
        return null;
    }

    /**
     * Datei-Schluessel aus dem aktuellen Warenkorb (Session) ermitteln:
     * [schluessel => dateiname]. Beim Auftrag anlegen ist der Warenkorb
     * noch vorhanden; die Schluessel (z. B. "8a7b0f40/Logo.png") sind
     * ueber OrderPropertyFileService lesbar.
     */
    public function basketFiles(): array
    {
        $out = [];
        try {
            /** @var BasketItemRepositoryContract $repo */
            $repo = pluginApp(BasketItemRepositoryContract::class);
            $items = [];
            try {
                $items = $repo->all([], ['basketItemOrderParams']);
                $this->diag[] = 'all(with) ok';
            } catch (\Throwable $x) {
                $this->diag[] = 'all(with) EXC ' . $x->getMessage();
                $items = $repo->all();
            }
            $idx = 0;
            foreach ($items as $item) {
                $idx++;
                $params = [];
                try {
                    $arr = $item->toArray();
                    $params = $arr['basketItemOrderParams'] ?? [];
                    if ($idx >= 3 && $idx <= 4) {
                        $this->diag[] = 'item' . $idx . ' ' . substr(json_encode($arr), 0, 700);
                    }
                } catch (\Throwable $x) {
                    $this->diag[] = 'item EXC ' . $x->getMessage();
                }
                if (!count($params)) {
                    try {
                        $rel = $item->basketItemOrderParams;
                        $params = is_array($rel) ? $rel : (is_object($rel) ? $rel->toArray() : []);
                        $this->diag[] = 'rel params ' . count($params);
                    } catch (\Throwable $x) {
                        $this->diag[] = 'rel EXC ' . $x->getMessage();
                    }
                }
                foreach ($params as $prm) {
                    $prm = (array)$prm;
                    $v = (string)($prm['value'] ?? '');
                    if ($v !== '' && preg_match('#^[a-z0-9]{6,}/[^/]+\.[A-Za-z0-9]{2,5}$#', $v)) {
                        $out[$v] = substr($v, strpos($v, '/') + 1);
                    }
                }
            }
            $this->diag[] = 'basket items ' . count($items) . ' files ' . implode(',', array_keys($out));
        } catch (\Throwable $e) {
            $this->diag[] = 'basket EXC ' . $e->getMessage();
        }
        return $out;
    }

    /**
     * Datei-Schluessel aus den Textmerkmalen des Auftrags ableiten.
     * Das Team-Script schreibt "hochgeladen: <Datei> (Ref <ordner>)" bzw.
     * "weitere Dateien: a.pdf, b.png (Ref <ordner>)"; die Upload-Schluessel
     * lauten "<ordner>/<Datei>". Druckvorschauen haben feste Namen.
     */
    public function refFiles($order): array
    {
        $out = [];
        if (!($order instanceof Order)) {
            return $out;
        }
        foreach ($order->orderItems as $item) {
            foreach ($item->properties as $p) {
                if ((int)$p->typeId !== 82) {
                    continue;
                }
                $v = (string)$p->value;
                if (!preg_match('/\\(Ref ([A-Za-z0-9_-]{4,40})\\)/', $v, $m)) {
                    continue;
                }
                $ref = $m[1];
                $names = [];
                if (preg_match('/hochgeladen: ([^|(]+)/', $v, $n)) {
                    $names[] = trim($n[1]);
                }
                if (preg_match('/weitere Dateien: ([^|(]+)/', $v, $n)) {
                    foreach (explode(',', $n[1]) as $x) {
                        $names[] = trim($x);
                    }
                }
                $names[] = 'Druckvorschau_Vorderseite.png';
                $names[] = 'Druckvorschau_Rueckseite.png';
                $names[] = 'Druckvorschau_Hose.png';
                foreach ($names as $name) {
                    if ($name === '' || strpos($name, '/') !== false || strpos($name, '..') !== false) {
                        continue;
                    }
                    $out[$ref . '/' . $name] = $name;
                }
            }
        }
        $this->diag[] = 'ref files ' . implode(',', array_keys($out));
        return $out;
    }

    /** Einzelnen Schluessel testen (Diagnose) */
    public function probe(string $key): array
    {
        /** @var OrderPropertyFileService $fileService */
        $fileService = pluginApp(OrderPropertyFileService::class);
        $r = $this->fetch($fileService, $key);
        return ['key' => $key, 'ok' => $r !== null, 'len' => $r ? strlen($r['body']) : 0, 'type' => $r ? $r['type'] : ''];
    }

    /**
     * Dateien anhand von Schluessel => Name als Auftragsdokumente ablegen.
     */
    public function attachFiles(int $orderId, array $files): array
    {
        if ($orderId <= 0 || !count($files)) {
            return [];
        }
        /** @var OrderPropertyFileService $fileService */
        $fileService = pluginApp(OrderPropertyFileService::class);
        /** @var DocumentRepositoryContract $docRepo */
        $docRepo = pluginApp(DocumentRepositoryContract::class);
        $docs = [];
        $done = [];
        foreach ($files as $key => $name) {
            try {
                $r = $this->fetch($fileService, (string)$key);
                if (!$r || $r['body'] === '') {
                    continue;
                }
                $name = (string)$name !== '' ? (string)$name : 'Datei' . $this->extension($r);
                $docs[] = [
                    'content' => base64_encode($r['body']),
                    'numberWithPrefix' => $name,
                    'numberWithoutPrefix' => $name,
                ];
                $done[] = $name;
            } catch (\Throwable $e) {
                $this->diag[] = 'attach ' . $key . ' EXC ' . $e->getMessage();
            }
        }
        if (count($docs)) {
            $docRepo->uploadOrderDocuments($orderId, 'uploaded', ['documents' => $docs]);
        }
        $this->diag[] = 'attached ' . implode(',', $done);
        return $done;
    }

    /** Diagnose-Text als Auftragsdokument ablegen (nur Testphase) */
    public function attachDiag(int $orderId, string $text)
    {
        try {
            /** @var DocumentRepositoryContract $docRepo */
            $docRepo = pluginApp(DocumentRepositoryContract::class);
            $docRepo->uploadOrderDocuments($orderId, 'uploaded', ['documents' => [[
                'content' => base64_encode($text),
                'numberWithPrefix' => 's39-diag.txt',
                'numberWithoutPrefix' => 's39-diag.txt',
            ]]]);
        } catch (\Throwable $e) {
        }
    }

    private function extension(array $obj): string
    {
        $ct = (string)($obj['type'] ?? '');
        $map = ['svg' => '.svg', 'png' => '.png', 'jpeg' => '.jpg', 'gif' => '.gif', 'webp' => '.webp', 'pdf' => '.pdf', 'postscript' => '.eps', 'photoshop' => '.psd', 'illustrator' => '.ai'];
        foreach ($map as $needle => $ext) {
            if (strpos($ct, $needle) !== false) {
                return $ext;
            }
        }
        return '';
    }

    private function log(string $key, array $data)
    {
        if (!$this->verbose()) {
            return;
        }
        try {
            $this->getLogger('S39Staffel')->info('S39Staffel::log.' . $key, $data);
        } catch (\Throwable $x) {
        }
    }
}

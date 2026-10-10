<?php

namespace S39Staffel\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Frontend\Services\OrderPropertyFileService;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;

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
                $body = $obj ? $obj->body : null;
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
                    return $obj;
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
                /** @var LibraryCallContract $lib */
                $lib = pluginApp(LibraryCallContract::class);
                $r = $lib->call('S39Staffel::fetch_image', ['url' => $url]);
                $status = is_array($r) ? (int)($r['status'] ?? 0) : 0;
                $this->diag[] = 'lib ' . $k . ' => status ' . $status . ' type ' . (is_array($r) ? ($r['type'] ?? '') : '') . ' ' . (is_array($r) && !empty($r['error_msg']) ? substr($r['error_msg'], 0, 100) : '');
                if ($status === 200 && !empty($r['body'])) {
                    $obj = new \stdClass();
                    $obj->body = base64_decode($r['body']);
                    $obj->metaData = ['headers' => ['content-type' => (string)($r['type'] ?? '')]];
                    return $obj;
                }
            } catch (\Throwable $e) {
                $this->diag[] = 'getFileURL ' . $k . ' => EXC ' . $e->getMessage();
            }
        }
        return null;
    }

    private function extension($obj): string
    {
        $ct = '';
        try {
            $ct = (string)($obj->metaData['headers']['content-type'] ?? '');
        } catch (\Throwable $e) {
        }
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

<?php

namespace S39Staffel\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Frontend\Services\OrderPropertyFileService;

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
     * Alle Datei-Merkmale der Auftragspositionen in die Auftragsdokumente kopieren.
     */
    public function copyPropertyFiles($order)
    {
        if (!($order instanceof Order) || (int)$order->id <= 0) {
            return;
        }
        $keys = [];
        foreach ($order->orderItems as $item) {
            $props = $item->orderProperties;
            if (!$props) {
                continue;
            }
            foreach ($props as $p) {
                $type = (string)$p->type;
                $value = (string)$p->value;
                if ($type !== 'file' || $value === '' || isset($keys[$value])) {
                    continue;
                }
                $keys[$value] = true;
            }
        }
        if (!count($keys)) {
            return;
        }

        /** @var OrderPropertyFileService $fileService */
        $fileService = pluginApp(OrderPropertyFileService::class);
        /** @var DocumentRepositoryContract $docRepo */
        $docRepo = pluginApp(DocumentRepositoryContract::class);

        $docs = [];
        $names = [];
        foreach (array_keys($keys) as $key) {
            try {
                $obj = $fileService->getFile($key);
                $body = $obj ? $obj->body : null;
                if ($body === null || $body === '') {
                    continue;
                }
                $parts = explode('/', $key);
                $name = $parts[count($parts) - 1];
                $docs[] = [
                    'content' => base64_encode($body),
                    'numberWithPrefix' => $name,
                    'numberWithoutPrefix' => $name,
                ];
                $names[] = $name;
            } catch (\Throwable $e) {
                $this->logError('getFile ' . $key, $e);
            }
        }
        if (!count($docs)) {
            return;
        }
        $result = $docRepo->uploadOrderDocuments((int)$order->id, 'uploaded', ['documents' => $docs]);
        if ($this->verbose()) {
            try {
                $this->getLogger('S39Staffel')->info('S39Staffel::log.files', [
                    'orderId' => $order->id,
                    'files' => $names,
                    'result' => is_array($result) ? count($result) : 'n/a',
                ]);
            } catch (\Throwable $x) {
            }
        }
    }
}

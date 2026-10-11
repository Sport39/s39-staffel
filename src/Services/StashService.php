<?php

namespace S39Staffel\Services;

use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;

/**
 * Ablage hochgeladener Kundendateien (Logos, Druckvorschauen) im
 * plugineigenen Speicher. Plenty verschiebt die Merkmal-Dateien beim
 * Anlegen des Auftrags; diese Kopie bleibt erreichbar und wird nach dem
 * Auftrag als Externes Dokument angehaengt.
 */
class StashService
{
    const PLUGIN = 'S39Staffel';
    const MAX_BYTES = 15728640; // 15 MB

    /** @var StorageRepositoryContract */
    private $storage;

    public function __construct(StorageRepositoryContract $storage)
    {
        $this->storage = $storage;
    }

    public static function cleanRef(string $ref): string
    {
        return preg_match('/^[A-Za-z0-9_-]{4,40}$/', $ref) ? $ref : '';
    }

    public static function cleanName(string $name): string
    {
        $name = trim(str_replace(['/', '\\'], '_', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $name = ltrim((string)$name, '.');
        return substr($name, 0, 120);
    }

    public static function key(string $ref, string $name): string
    {
        return 'stash/' . $ref . '/' . self::cleanName($name);
    }

    /**
     * @return string Fehlertext oder '' bei Erfolg
     */
    public function put(string $ref, string $name, string $body, string $type): string
    {
        $ref = self::cleanRef($ref);
        if ($ref === '' || self::cleanName($name) === '') {
            return 'ref/name ungueltig';
        }
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return 'Datei leer oder zu gross';
        }
        $this->storage->uploadObject(self::PLUGIN, self::key($ref, $name), $body, false, ['content-type' => $type]);
        return '';
    }

    /**
     * @return array|null ['body' => string, 'type' => string]
     */
    public function get(string $ref, string $name)
    {
        $ref = self::cleanRef($ref);
        if ($ref === '') {
            return null;
        }
        $key = self::key($ref, $name);
        if (!$this->storage->doesObjectExist(self::PLUGIN, $key)) {
            return null;
        }
        $obj = $this->storage->getObject(self::PLUGIN, $key);
        if (!$obj || !isset($obj->body) || (string)$obj->body === '') {
            return null;
        }
        $type = '';
        try {
            $type = (string)($obj->metaData['content-type'] ?? ($obj->metaData['headers']['content-type'] ?? ''));
        } catch (\Throwable $e) {
        }
        return ['body' => (string)$obj->body, 'type' => $type];
    }
}

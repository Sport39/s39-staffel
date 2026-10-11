<?php

namespace S39Staffel\Services;

use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;

/**
 * Laedt eine Datei per URL serverseitig ueber das lib-Skript (Guzzle).
 */
class DownloadService
{
    /** @var LibraryCallContract */
    private $libCall;

    public function __construct(LibraryCallContract $libCall)
    {
        $this->libCall = $libCall;
    }

    /**
     * @return array|null ['body' => string, 'type' => string] oder null
     */
    public function get(string $url)
    {
        $result = $this->libCall->call('S39Staffel::fetch_image', ['url' => $url]);
        if (!is_array($result) || (int)($result['status'] ?? 0) !== 200 || empty($result['body'])) {
            return null;
        }
        return [
            'body' => base64_decode($result['body']),
            'type' => (string)($result['type'] ?? ''),
        ];
    }

    public function describe($result): string
    {
        if (!is_array($result)) {
            return 'no result';
        }
        return 'status ' . ($result['status'] ?? '') . ' ' . ($result['error_msg'] ?? '');
    }
}

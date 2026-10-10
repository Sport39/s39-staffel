<?php
/**
 * Laedt ein Artikelbild vom Plenty-CDN (serverseitig, ohne CORS-Beschraenkung)
 * und gibt es base64-kodiert zurueck. Wird ueber LibraryCallContract aufgerufen.
 */
$url = (string)SdkRestApi::getParam('url', '');
if ($url === '') {
    return ['status' => 400, 'error' => 'no url'];
}
try {
    $client = new \GuzzleHttp\Client(['timeout' => 15, 'http_errors' => false]);
    $res = $client->get($url);
    return [
        'status' => $res->getStatusCode(),
        'type' => $res->getHeaderLine('Content-Type'),
        'body' => base64_encode((string)$res->getBody()),
    ];
} catch (\Throwable $e) {
    return ['status' => 500, 'error' => $e->getMessage()];
}

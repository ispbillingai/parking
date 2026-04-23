<?php
declare(strict_types=1);

namespace Parking\Notify;

class TextMeBot
{
    public function __construct(private array $cfg) {}

    public function sendWhatsapp(string $phoneE164, string $text): array
    {
        $url = $this->cfg['endpoint'] . '?' . http_build_query([
            'recipient' => $phoneE164,
            'apikey'    => $this->cfg['api_key'],
            'text'      => $text,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ok = ($body !== false) && $http === 200 && stripos((string) $body, 'success') !== false;
        return [
            'ok'    => $ok,
            'http'  => $http,
            'body'  => $body,
            'error' => $err,
        ];
    }
}

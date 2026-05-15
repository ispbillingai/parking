<?php
declare(strict_types=1);

namespace Parking\Fiscal;

use SimpleXMLElement;

/**
 * Thin client for the Epson fpmate.cgi SOAP-XML service running on the
 * fiscal printer (Registratore Telematico). Two responsibilities:
 *
 *   1) authorizeSales — tell the printer to talk Protocol 17 to the
 *      attached EFT-POS terminal and run a card payment.
 *   2) emit — push a printerFiscalReceipt XML payload so the printer
 *      produces the fiscal receipt (commercial document).
 *
 * The wire format is documented in the Epson "ePOS Fiscal Print Solution
 * Development Guide" (FP 000 055 EN Rev. U). Under the XML layer, the
 * printer speaks A.PDU (FP 000 008 EN Rev. 8.10) — error codes returned
 * by the printer match that document's §8.3 error table, so the log
 * hints reference it for diagnosis.
 *
 * Every public method opens a Log scope (correlation id) so that the
 * request, response, parse step and final outcome all appear under the
 * same `rid` in storage/logs/fiscal-YYYY-MM-DD.log.
 */
final class Client
{
    public function __construct(private array $cfg) {}

    public function enabled(): bool
    {
        return !empty($this->cfg['base_url']);
    }

    /**
     * Trigger an EFT-POS card authorisation. Blocks until the customer
     * taps the card and the POS terminal returns (up to ~30s). The
     * printer holds the receipt lines internally so a subsequent emit()
     * can splice them via printRecMessage messageType=8.
     *
     * @return array{ok:bool, error?:string, status?:string, lines?:array<int,string>}
     */
    public function authorizeSales(int $amountCents): array
    {
        $operator = (string) ($this->cfg['operator'] ?? '1');
        $rid = Log::begin('authorizeSales', [
            'amount_cents' => $amountCents,
            'operator'     => $operator,
            'base_url'     => $this->cfg['base_url'] ?? null,
        ]);

        try {
            if (!$this->enabled()) {
                Log::error('fiscal_printer_disabled', ['where' => 'authorizeSales']);
                return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
            }

            $amount = number_format($amountCents / 100, 2, '.', '');

            $xml = sprintf(
                '<printerCommand><authorizeSales operator="%s" amount="%s" /></printerCommand>',
                htmlspecialchars($operator, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                $amount
            );

            Log::info('authorize_request_built', [
                'amount'   => $amount,
                'operator' => $operator,
            ]);

            $res = $this->request($xml, [
                'timeout' => (int) ($this->cfg['timeout_ms'] ?? 35000),
            ]);

            if (!$res['ok']) {
                // request() already logged transport/parse failure with hint.
                return $res;
            }

            $add    = $res['add_info'] ?? [];
            $result = (string) ($add['transactionResult'] ?? '');

            // Per the dev guide: transactionResult "00" = approved, "01" = declined.
            if ($result === '00') {
                $out = [
                    'ok'     => true,
                    'status' => 'approved',
                    'lines'  => $this->extractLines($add),
                ];
                Log::info('authorize_approved', [
                    'line_count' => count($out['lines']),
                ]);
                return $out;
            }

            $err = trim((string) ($add['failureDescription'] ?? '')) ?: 'card_declined';
            $event = $result !== '' ? 'card_declined' : 'authorize_unknown';
            Log::error($event, [
                'error'              => $err,
                'transactionResult'  => $result,
                'failureDescription' => $add['failureDescription'] ?? null,
            ]);

            return [
                'ok'     => false,
                'error'  => $err,
                'status' => $result !== '' ? 'declined' : 'unknown',
                'lines'  => $this->extractLines($add),
            ];
        } finally {
            Log::end($rid);
        }
    }

    /**
     * Emit a fiscal receipt. The caller passes an array of line specs
     * (see Receipt::build) and the payment method:
     *   - 'cash'    → printRecTotal paymentType=0
     *   - 'card'    → printRecTotal paymentType=2 + EFT-POS lines appended
     *
     * @param array<int,array{description:string,quantity:string,unitPrice:string,department?:int}> $items
     * @return array{ok:bool, error?:string, receipt_number?:string, receipt_date?:string}
     */
    public function emit(array $items, int $amountCents, string $method): array
    {
        $operator = (string) ($this->cfg['operator'] ?? '1');
        $rid = Log::begin('emit', [
            'amount_cents' => $amountCents,
            'method'       => $method,
            'operator'     => $operator,
            'item_count'   => count($items),
            'items'        => $this->summariseItems($items),
            'base_url'     => $this->cfg['base_url'] ?? null,
        ]);

        try {
            if (!$this->enabled()) {
                Log::error('fiscal_printer_disabled', ['where' => 'emit']);
                return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
            }

            $xml = Receipt::build($items, $amountCents, $method, $operator);
            Log::info('emit_xml_built', ['xml_length' => strlen($xml)]);

            $res = $this->request($xml);
            if (!$res['ok']) {
                // request() already logged transport/parse/printer failure.
                Log::error('fiscal_emit_failed', [
                    'error'        => $res['error']  ?? '?',
                    'status'       => $res['status'] ?? null,
                    'amount_cents' => $amountCents,
                    'method'       => $method,
                ]);
                return $res;
            }

            $add = $res['add_info'] ?? [];
            $out = [
                'ok'             => true,
                'receipt_number' => (string) ($add['fiscalReceiptNumber'] ?? ''),
                'receipt_date'   => (string) ($add['fiscalReceiptDate']   ?? ''),
                'receipt_time'   => (string) ($add['fiscalReceiptTime']   ?? ''),
                'z_rep_number'   => (string) ($add['zRepNumber']          ?? ''),
                'serial_number'  => (string) ($add['serialNumber']        ?? ''),
            ];

            Log::info('emit_success', [
                'receipt_number' => $out['receipt_number'],
                'receipt_date'   => $out['receipt_date'],
                'receipt_time'   => $out['receipt_time'],
                'z_rep_number'   => $out['z_rep_number'],
                'serial_number'  => $out['serial_number'],
            ]);

            return $out;
        } finally {
            Log::end($rid);
        }
    }

    /**
     * POST a raw fiscal-ePOS-Print XML body to fpmate.cgi.
     *
     * @return array{ok:bool, error?:string, code?:string, status?:string, add_info?:array<string,string>}
     */
    private function request(string $bodyXml, array $opts = []): array
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body>' . $bodyXml . '</s:Body>'
            . '</s:Envelope>';

        $query = [];
        if (!empty($opts['timeout'])) $query['timeout'] = (int) $opts['timeout'];
        $url = rtrim((string) $this->cfg['base_url'], '/') . '/cgi-bin/fpmate.cgi';
        if ($query) $url .= '?' . http_build_query($query);

        $timeoutMs = (int) ($opts['timeout'] ?? ($this->cfg['timeout_ms'] ?? 15000));
        $curlTimeout = max(1, (int) ($timeoutMs / 1000) + 5);

        Log::info('http_request', [
            'url'         => $url,
            'method'      => 'POST',
            'timeout_ms'  => $timeoutMs,
            'curl_to_sec' => $curlTimeout,
            'verify_ssl'  => !empty($this->cfg['verify_ssl']),
            'body_length' => strlen($envelope),
            'body'        => $this->truncate($envelope, 4000),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
            ],
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['verify_ssl']) ? 2 : 0,
            CURLOPT_TIMEOUT        => $curlTimeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $started = microtime(true);
        $raw = curl_exec($ch);
        $durMs = (int) ((microtime(true) - $started) * 1000);

        if ($raw === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $info  = curl_getinfo($ch);
            curl_close($ch);

            Log::error('curl: ' . $err, [
                'error'         => 'curl: ' . $err,
                'curl_errno'    => $errno,
                'duration_ms'   => $durMs,
                'connect_time'  => $info['connect_time']     ?? null,
                'namelookup'    => $info['namelookup_time']  ?? null,
                'primary_ip'    => $info['primary_ip']       ?? null,
                'primary_port'  => $info['primary_port']     ?? null,
                'url'           => $url,
            ]);

            return ['ok' => false, 'error' => 'curl: ' . $err];
        }

        $httpCode = (int) (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        $info     = curl_getinfo($ch);
        curl_close($ch);

        Log::info('http_response', [
            'http_status'  => $httpCode,
            'duration_ms'  => $durMs,
            'primary_ip'   => $info['primary_ip']   ?? null,
            'primary_port' => $info['primary_port'] ?? null,
            'body_length'  => strlen((string) $raw),
            'body'         => $this->truncate((string) $raw, 4000),
        ]);

        return $this->parse((string) $raw);
    }

    /**
     * Strip the SOAP envelope and lift response attributes + addInfo
     * children into a flat array. fpmate.cgi returns one <response>
     * element with success/code/status attributes and an optional
     * <addInfo> block whose children vary per command.
     *
     * @return array{ok:bool, error?:string, code?:string, status?:string, add_info?:array<string,string|array<int,string>>}
     */
    private function parse(string $raw): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($raw);
            $libErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$doc instanceof SimpleXMLElement) {
            $errs = array_map(static fn($e) => trim($e->message ?? ''), $libErrors ?: []);
            Log::error('parse_failed', [
                'error'      => 'invalid_xml',
                'libxml'     => $errs,
                'raw_excerpt' => substr($raw, 0, 500),
            ]);
            return ['ok' => false, 'error' => 'invalid_xml', 'raw' => substr($raw, 0, 500)];
        }
        // The SOAP envelope prefix varies by printer firmware — "s:",
        // "soapenv:", "soap:" etc. Locate the <response> element by its
        // local name via XPath so parsing works regardless of the prefix
        // (this printer replies with "soapenv:", the dev-guide uses "s:").
        $found    = $doc->xpath('//*[local-name()="response"]');
        $response = $found[0] ?? null;
        if (!$response) {
            Log::error('no_response_node', [
                'error'       => 'no_response_node',
                'raw_excerpt' => substr($raw, 0, 500),
            ]);
            return ['ok' => false, 'error' => 'no_response_node', 'raw' => substr($raw, 0, 500)];
        }

        $attrs   = $response->attributes();
        $success = (string) ($attrs['success'] ?? 'false') === 'true';
        $code    = (string) ($attrs['code']   ?? '');
        $status  = (string) ($attrs['status'] ?? '');

        $addInfo = [];
        if (isset($response->addInfo)) {
            foreach ($response->addInfo->children() as $name => $child) {
                $key = (string) $name;
                if ($key === 'lineCount') {
                    $lines = [];
                    foreach ($child->children() as $ln) {
                        $lines[] = (string) $ln;
                    }
                    $addInfo['lines'] = $lines;
                    $addInfo['lineCount'] = (string) $child;
                } else {
                    $addInfo[$key] = (string) $child;
                }
            }
        }

        Log::info('parse_ok', [
            'success'        => $success,
            'code'           => $code,
            'status'         => $status,
            'add_info_keys'  => array_keys($addInfo),
        ]);

        if (!$success) {
            Log::error('printer_error', [
                'error'    => $code !== '' ? $code : 'printer_error',
                'code'     => $code,
                'status'   => $status,
                'add_info' => $this->summariseAddInfo($addInfo),
            ]);
            return [
                'ok'       => false,
                'error'    => $code !== '' ? $code : 'printer_error',
                'status'   => $status,
                'add_info' => $addInfo,
            ];
        }

        return [
            'ok'       => true,
            'code'     => $code,
            'status'   => $status,
            'add_info' => $addInfo,
        ];
    }

    private function extractLines(array $add): array
    {
        return is_array($add['lines'] ?? null) ? $add['lines'] : [];
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '...[' . (strlen($s) - $max) . ' more bytes]';
    }

    /**
     * Strip noisy/long fields out of addInfo so the error log entry
     * stays scannable. The full block is still in the parse_ok line
     * above for forensic reconstruction.
     */
    private function summariseAddInfo(array $add): array
    {
        $out = [];
        foreach ($add as $k => $v) {
            if (is_array($v)) {
                $out[$k] = 'array(' . count($v) . ')';
            } else {
                $s = (string) $v;
                $out[$k] = strlen($s) > 120 ? substr($s, 0, 120) . '...' : $s;
            }
        }
        return $out;
    }

    private function summariseItems(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $out[] = [
                'desc' => (string) ($it['description'] ?? ''),
                'qty'  => (string) ($it['quantity']    ?? ''),
                'unit' => (string) ($it['unitPrice']   ?? ''),
                'dept' => (string) ($it['department']  ?? ''),
            ];
        }
        return $out;
    }
}

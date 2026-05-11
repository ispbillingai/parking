<?php
declare(strict_types=1);

namespace Parking\Fiscal;

use RuntimeException;
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
 * Development Guide" (FP 000 055 EN Rev. U). All responses are parsed
 * into a normalized PHP array regardless of which root element the
 * printer returned.
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
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }

        $operator = (string) ($this->cfg['operator'] ?? '1');
        $amount   = number_format($amountCents / 100, 2, '.', '');

        $xml = sprintf(
            '<printerCommand><authorizeSales operator="%s" amount="%s" /></printerCommand>',
            htmlspecialchars($operator, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $amount
        );

        $res = $this->request($xml, [
            'timeout' => (int) ($this->cfg['timeout_ms'] ?? 35000),
        ]);

        if (!$res['ok']) return $res;

        $add    = $res['add_info'] ?? [];
        $result = (string) ($add['transactionResult'] ?? '');

        // Per the dev guide: transactionResult "00" = approved, "01" = declined.
        if ($result === '00') {
            return [
                'ok'     => true,
                'status' => 'approved',
                'lines'  => $this->extractLines($add),
            ];
        }

        return [
            'ok'     => false,
            'error'  => trim($add['failureDescription'] ?? 'card_declined'),
            'status' => $result !== '' ? 'declined' : 'unknown',
            'lines'  => $this->extractLines($add),
        ];
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
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }

        $operator = (string) ($this->cfg['operator'] ?? '1');
        $xml = Receipt::build($items, $amountCents, $method, $operator);

        $res = $this->request($xml);
        if (!$res['ok']) return $res;

        $add = $res['add_info'] ?? [];
        return [
            'ok'             => true,
            'receipt_number' => (string) ($add['fiscalReceiptNumber'] ?? ''),
            'receipt_date'   => (string) ($add['fiscalReceiptDate']   ?? ''),
            'receipt_time'   => (string) ($add['fiscalReceiptTime']   ?? ''),
            'z_rep_number'   => (string) ($add['zRepNumber']          ?? ''),
            'serial_number'  => (string) ($add['serialNumber']        ?? ''),
        ];
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
            CURLOPT_TIMEOUT        => (int) (($opts['timeout'] ?? ($this->cfg['timeout_ms'] ?? 15000)) / 1000) + 5,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'curl: ' . $err];
        }
        curl_close($ch);

        return $this->parse($raw);
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
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$doc instanceof SimpleXMLElement) {
            return ['ok' => false, 'error' => 'invalid_xml', 'raw' => substr($raw, 0, 500)];
        }
        $ns = $doc->getNamespaces(true);
        $body = $ns['s'] ?? null
            ? $doc->children($ns['s'])->Body
            : $doc->Body;
        $response = $body->response ?? null;
        if (!$response) {
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

        if (!$success) {
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
}

<?php
declare(strict_types=1);

namespace Parking\Fiscal;

/**
 * Builds the <printerFiscalReceipt> XML payload sent to fpmate.cgi.
 *
 * One commercial document = one or more printRecItem lines, optionally
 * an EFT-POS line splice (printRecMessage messageType=8) when paying
 * by card so the POS slip lands on the same receipt, then one
 * printRecTotal that closes the payment.
 *
 * paymentType per the Epson dev guide:
 *   0 = Cash
 *   2 = Electronic (card, etc.)
 */
final class Receipt
{
    /**
     * @param array<int,array{description:string,quantity:string,unitPrice:string,department?:int}> $items
     * @param int    $amountCents  total to charge (cents)
     * @param string $method       'cash' | 'card'
     * @param string $operator     operator ID (printer-side)
     */
    public static function build(array $items, int $amountCents, string $method, string $operator): string
    {
        $op = self::attr($operator);
        $payType = $method === 'card' ? '2' : '0';
        $payDesc = $method === 'card' ? 'PAGAMENTO CARTA' : 'CONTANTE';
        $payment = number_format($amountCents / 100, 2, '.', '');

        $xml  = '<printerFiscalReceipt>';
        $xml .= '<beginFiscalReceipt operator="' . $op . '" />';

        foreach ($items as $it) {
            $xml .= '<printRecItem'
                  . ' operator="' . $op . '"'
                  . ' description="' . self::attr((string) ($it['description'] ?? '')) . '"'
                  . ' quantity="' . self::attr((string) ($it['quantity'] ?? '1')) . '"'
                  . ' unitPrice="' . self::attr((string) ($it['unitPrice'] ?? '0.00')) . '"'
                  . ' department="' . self::attr((string) ($it['department'] ?? 1)) . '"'
                  . ' justification="1" />';
        }

        // Append the buffered EFT-POS slip lines (and consume them) when
        // the customer paid by card. clearEFTPOSBuffer=0 keeps the lines
        // visible on the receipt; the buffer is flushed afterwards by
        // the printer per the dev guide.
        if ($method === 'card') {
            $xml .= '<printRecMessage operator="' . $op . '" messageType="8" clearEFTPOSBuffer="0" />';
        }

        $xml .= '<printRecTotal'
              . ' operator="' . $op . '"'
              . ' description="' . self::attr($payDesc) . '"'
              . ' payment="' . $payment . '"'
              . ' paymentType="' . $payType . '"'
              . ' index="1" />';

        $xml .= '<endFiscalReceipt operator="' . $op . '" />';
        $xml .= '</printerFiscalReceipt>';
        return $xml;
    }

    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

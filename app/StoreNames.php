<?php

namespace App;

/**
 * Store number => store name for the cover sheet. The ASN of a Tax Invoice gives only the number, so names come from
 * assets/cover/store_names.json (read from the sample sheet of March 2026, edit it to add a store) and from the store
 * line of the Job's credit notes ("5002-CENTRAL PHUKET 10121"). The file wins over a credit note.
 */
class StoreNames
{
    /** @var array key => name */
    private $names = [];

    /** @var array key => name learned from credit notes */
    private $learned = [];

    public function __construct(?string $file = null)
    {
        $file = $file ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'cover' . DIRECTORY_SEPARATOR . 'store_names.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        foreach (is_array($data) ? $data : [] as $store => $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $this->names[self::key((string)$store)] = $name;
            }
        }
    }

    /** A store number without leading zeros, as the credit note compare matches stores */
    public static function key(string $store): string
    {
        $store = trim($store);
        $stripped = ltrim($store, '0');
        return $stripped === '' && $store !== '' ? '0' : $stripped;
    }

    /**
     * Names from the store line of credit notes: "5002-CENTRAL PHUKET 10121" names store 10121 CENTRAL PHUKET
     */
    public function learnFromCreditNotes(array $docs): void
    {
        foreach ($docs as $doc) {
            $name = self::nameOnStoreLine((string)($doc['customer'] ?? ''), (string)($doc['store_no'] ?? ''));
            if ($name !== null) {
                $this->learned[self::key((string)$doc['store_no'])] = $name;
            }
        }
    }

    public static function nameOnStoreLine(string $line, string $store): ?string
    {
        $store = trim($store);
        if ($store === '' || !preg_match('/^\s*\d+\s*-\s*(.+?)\s+(\d+)\s*$/u', $line, $m) || self::key($m[2]) !== self::key($store)) {
            return null;
        }
        return trim($m[1]);
    }

    public function name(string $store): string
    {
        $key = self::key($store);
        return $this->names[$key] ?? $this->learned[$key] ?? '';
    }
}

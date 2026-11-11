<?php

namespace App;

use PDO;
use PDOException;

/**
 * SQLite Database Manager for Jobs and Reconciliation
 * Compatible with PHP 7.0 - 8.4
 */
class Database
{
    /** PRAGMA user_version of a database that has every table and column below */
    public const SCHEMA_VERSION = 9;

    /** What CreditNoteReader adds to a credit note document (schema 9); NULL on documents stored before */
    private const CN_READER_COLUMNS = [
        'store_no' => 'TEXT', 'doc_date_iso' => 'TEXT', 'shipped_units' => 'INTEGER', 'promo_discount' => 'REAL',
        'total_amount' => 'REAL', 'total_discount' => 'REAL', 'after_discount' => 'REAL', 'excl_vat' => 'REAL',
        'vat' => 'REAL', 'incl_vat' => 'REAL', 'gp_percent' => 'REAL', 'read_ok' => 'INTEGER', 'read_note' => 'TEXT',
    ];

    /** @var PDO|null */
    private static $instance = null;

    /**
     * Get singleton PDO connection
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dataDir = Config::getRootPath() . DIRECTORY_SEPARATOR . 'data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0777, true);
            }

            $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'app.sqlite';
            $isNew = !file_exists($dbPath);

            try {
                self::$instance = new PDO("sqlite:" . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                self::$instance->exec("PRAGMA synchronous = NORMAL; PRAGMA temp_store = MEMORY; PRAGMA cache_size = -20000; PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON;");

                if ($isNew || filesize($dbPath) === 0) {
                    self::$instance->exec("PRAGMA journal_mode = WAL;");
                    self::initSchema(self::$instance);
                    self::$instance->exec("PRAGMA user_version = " . self::SCHEMA_VERSION . ";");
                } else {
                    self::migrateSchema(self::$instance);
                }
            } catch (PDOException $e) {
                throw new \RuntimeException("Database Connection Error: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Ensure existing SQLite databases have the latest columns and indexes
     *
     * @param PDO $pdo
     */
    public static function migrateSchema(PDO $pdo): void
    {
        $verStmt = $pdo->query("PRAGMA user_version");
        $ver = $verStmt ? (int)$verStmt->fetchColumn() : 0;
        if ($ver >= self::SCHEMA_VERSION) {
            return;
        }

        $pdo->exec("PRAGMA journal_mode = WAL;");

        $cols = [];
        $stmt = $pdo->query("PRAGMA table_info(job_asn_records)");
        if ($stmt) {
            while ($r = $stmt->fetch()) {
                $cols[$r['name']] = true;
            }
        }
        $added = false;
        if (!isset($cols['total_vat'])) {
            $pdo->exec("ALTER TABLE job_asn_records ADD COLUMN total_vat REAL DEFAULT NULL");
            $added = true;
        }
        if (!isset($cols['delivery_no'])) {
            $pdo->exec("ALTER TABLE job_asn_records ADD COLUMN delivery_no TEXT DEFAULT NULL");
            $added = true;
        }
        if (!isset($cols['source_file'])) {
            $pdo->exec("ALTER TABLE job_asn_records ADD COLUMN source_file TEXT DEFAULT ''");
            $pdo->exec("
                UPDATE job_asn_records
                SET source_file = COALESCE(
                    (SELECT asn_filename FROM jobs WHERE jobs.id = job_asn_records.job_id),
                    'ASN_Upload.xlsx'
                )
                WHERE source_file IS NULL OR source_file = ''
            ");
        }
        if ($added) {
            $pdo->exec("
                UPDATE job_asn_records
                SET total_vat = COALESCE(CAST(json_extract(raw_json, '$.total_vat') AS REAL), 0),
                    delivery_no = COALESCE(json_extract(raw_json, '$.delivery_no'), '')
                WHERE raw_json IS NOT NULL AND total_vat IS NULL
            ");
        }

        $jobCols = [];
        $jobStmt = $pdo->query("PRAGMA table_info(jobs)");
        if ($jobStmt) {
            while ($r = $jobStmt->fetch()) {
                $jobCols[$r['name']] = true;
            }
        }
        if (!isset($jobCols['asn_reconciled'])) {
            $pdo->exec("ALTER TABLE jobs ADD COLUMN asn_reconciled INTEGER DEFAULT 0");
        }
        if (!isset($jobCols['asn_pending_fix_count'])) {
            $pdo->exec("ALTER TABLE jobs ADD COLUMN asn_pending_fix_count INTEGER DEFAULT 0");
        }

        // A version 8 database already has job_cn_docs; an older one gets the whole table from initSchema
        $cnCols = [];
        $cnStmt = $pdo->query("PRAGMA table_info(job_cn_docs)");
        if ($cnStmt) {
            while ($r = $cnStmt->fetch()) {
                $cnCols[$r['name']] = true;
            }
        }
        if ($cnCols) {
            foreach (self::CN_READER_COLUMNS as $column => $type) {
                if (!isset($cnCols[$column])) {
                    $pdo->exec("ALTER TABLE job_cn_docs ADD COLUMN {$column} {$type} DEFAULT NULL");
                }
            }
        }

        self::initSchema($pdo);

        $pdo->exec("PRAGMA user_version = " . self::SCHEMA_VERSION . ";");
    }

    /**
     * Initialize SQLite schema
     *
     * @param PDO $pdo
     */
    public static function initSchema(PDO $pdo): void
    {
        // 1. Jobs Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                status TEXT DEFAULT 'active',
                total_files INTEGER DEFAULT 0,
                total_items INTEGER DEFAULT 0,
                total_amount REAL DEFAULT 0,
                asn_filename TEXT DEFAULT NULL,
                asn_matched_count INTEGER DEFAULT 0,
                asn_mismatch_count INTEGER DEFAULT 0,
                asn_pending_fix_count INTEGER DEFAULT 0,
                asn_reconciled INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // 2. Job Files Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                page_count INTEGER DEFAULT 1,
                extracted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(job_id, filename),
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
        ");

        // 3. Job Items (Extracted PDF Rows) Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                file_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                page_no INTEGER DEFAULT 1,
                tax_invoice TEXT,
                date TEXT,
                address TEXT,
                tax_id TEXT,
                branch_code TEXT,
                ship_to_party TEXT,
                term_a TEXT,
                term_b TEXT,
                product_sz TEXT,
                barcode TEXT,
                description TEXT,
                units INTEGER DEFAULT 0,
                unit_price REAL DEFAULT 0,
                amount REAL DEFAULT 0,
                total_shipped TEXT,
                no_cartons TEXT,
                promotion_discount TEXT,
                total_amount TEXT,
                discount_percent TEXT,
                total_discount TEXT,
                amt_after_discount TEXT,
                amt_excl_vat TEXT,
                vat_amount TEXT,
                amt_incl_vat TEXT,
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                FOREIGN KEY (file_id) REFERENCES job_files(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_items_job_invoice ON job_items(job_id, tax_invoice);
            CREATE INDEX IF NOT EXISTS idx_items_job_barcode ON job_items(job_id, barcode);
        ");

        // 4. Job ASN Records Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_asn_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                source_file TEXT DEFAULT '',
                invoice_no TEXT,
                invoice_date TEXT,
                gp_date TEXT,
                exp_delivery_date TEXT,
                ship_via TEXT,
                trailer_nbr TEXT,
                dest_store_no TEXT,
                ean_upc TEXT,
                quantity INTEGER DEFAULT 0,
                selling_price REAL DEFAULT 0,
                total_cost_ex_vat REAL DEFAULT 0,
                total_vat REAL DEFAULT 0,
                delivery_no TEXT DEFAULT '',
                raw_json TEXT,
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_asn_job_invoice ON job_asn_records(job_id, invoice_no);
            CREATE INDEX IF NOT EXISTS idx_asn_job_ean ON job_asn_records(job_id, ean_upc);
        ");

        // 5. Invoices the fix button took out of "waiting for fix"
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_invoice_fixes (
                job_id INTEGER NOT NULL,
                invoice_no TEXT NOT NULL,
                fixed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (job_id, invoice_no),
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_invoice_fix_steps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                invoice_no TEXT NOT NULL,
                kind TEXT NOT NULL,
                filename TEXT NOT NULL,
                page_no INTEGER NOT NULL,
                from_value TEXT NOT NULL DEFAULT '',
                UNIQUE (job_id, filename, page_no, kind),
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_fix_steps_job_invoice ON job_invoice_fix_steps(job_id, invoice_no);
        ");

        // 6. Credit notes read from the Job's CREDIT NOTE folders, one row per credit note document
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_cn_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                page_count INTEGER DEFAULT 1,
                extracted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(job_id, filename),
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_cn_docs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                file_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                doc_no TEXT NOT NULL,
                doc_date TEXT,
                customer TEXT,
                format_type TEXT NOT NULL,
                pages TEXT,
                orig_amount REAL DEFAULT NULL,
                corr_amount REAL DEFAULT NULL,
                diff_amount REAL DEFAULT NULL,
                vat_amount REAL DEFAULT NULL,
                amount REAL DEFAULT NULL,
                " . implode('', array_map(function ($column, $type) {
                    return "{$column} {$type} DEFAULT NULL,\n";
                }, array_keys(self::CN_READER_COLUMNS), self::CN_READER_COLUMNS)) . "
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                FOREIGN KEY (file_id) REFERENCES job_cn_files(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_cn_docs_job_doc ON job_cn_docs(job_id, doc_no);
        ");

        // 7. The item lines CreditNoteReader reads, one row per printed line of a credit note document
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_cn_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                file_id INTEGER NOT NULL,
                doc_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                doc_no TEXT NOT NULL,
                barcode TEXT NOT NULL,
                product_code TEXT,
                description TEXT,
                units INTEGER,
                unit_price REAL,
                amount REAL,
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                FOREIGN KEY (file_id) REFERENCES job_cn_files(id) ON DELETE CASCADE,
                FOREIGN KEY (doc_id) REFERENCES job_cn_docs(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_cn_items_job_doc ON job_cn_items(job_id, doc_no);
        ");

        // 8. ASN rows the credit note compare reads, apart from the Tax Invoice ones; source_file is '<workbook> / <sheet>'
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_cn_asn_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                source_file TEXT NOT NULL DEFAULT '',
                invoice_no TEXT,
                invoice_date TEXT,
                gp_date TEXT,
                exp_delivery_date TEXT,
                ship_via TEXT,
                trailer_nbr TEXT,
                dest_store_no TEXT,
                ean_upc TEXT,
                quantity INTEGER DEFAULT 0,
                selling_price REAL DEFAULT 0,
                total_cost_ex_vat REAL DEFAULT 0,
                total_vat REAL DEFAULT 0,
                delivery_no TEXT DEFAULT '',
                amount_kind TEXT NOT NULL CHECK (amount_kind IN ('cost', 'retail')),
                raw_json TEXT,
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_cn_asn_job_invoice ON job_cn_asn_records(job_id, invoice_no);
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_cn_state (
                job_id INTEGER PRIMARY KEY,
                asn_reconciled INTEGER NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            );
        ");
    }
}

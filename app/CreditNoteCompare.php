<?php

namespace App;

/**
 * Tabs 2 and 3 of the credit note page: the ASN sheets of the compare, the compare itself and the dashboard. Every
 * method answers what its job_cn_* action sends after 'status'. Comparing marks the Job compared (job_cn_state);
 * after that every read compares again on the current rows, as the Tax Invoice side does.
 */
class CreditNoteCompare
{
    /** @var CreditNoteAsnStore */
    private $asn;

    /** @var CreditNoteStore */
    private $docs;

    /** @var JobRepository */
    private $jobs;

    public function __construct(CreditNoteAsnStore $asn, CreditNoteStore $docs, JobRepository $jobs)
    {
        $this->asn = $asn;
        $this->docs = $docs;
        $this->jobs = $jobs;
    }

    public function importAsn(int $jobId, string $source, array $records, bool $append, string $duplicateMode, string $sameFileMode, bool $reconcile): array
    {
        $this->requireJob($jobId);
        if (trim($source) === '') {
            throw new \InvalidArgumentException('ไม่มีชื่อไฟล์และชีตของ ASN จึงยังไม่ได้นำเข้า');
        }
        if (!$records) {
            throw new \InvalidArgumentException('ไม่มีแถว ASN ให้นำเข้าจากชีตนี้');
        }
        $saved = $this->asn->save($jobId, $source, $records, $append, $duplicateMode, $sameFileMode);
        return [
            'imported_records' => $saved['imported'],
            'skipped_invoices' => $saved['skipped_invoices'],
            'replaced_invoices' => $saved['replaced_invoices'],
            'skipped_same_file' => $saved['skipped_same_file'],
            'replaced_same_file' => $saved['replaced_same_file'],
        ] + $this->asnState($jobId, $reconcile);
    }

    public function asnOverlap(int $jobId, string $source, array $records): array
    {
        $this->requireJob($jobId);
        $overlap = $this->asn->findOverlap($jobId, $source, array_map(function ($r): string {
            return trim((string)($r['invoice_no'] ?? ''));
        }, $records));
        return [
            'same_file' => $this->asn->describeStored($jobId, $source, $records),
            'overlap_count' => count($overlap),
            'files' => array_values(array_unique(array_merge([], ...array_values($overlap)))),
            'sample' => array_slice(array_map('strval', array_keys($overlap)), 0, 5),
        ];
    }

    public function asnRecords(int $jobId): array
    {
        $this->requireJob($jobId);
        $records = $this->asn->records($jobId);
        return [
            'count' => count($records),
            'asn_filename' => $this->asn->label($jobId),
            'asn_reconciled' => (int)$this->asn->isReconciled($jobId),
            'data' => $records,
        ];
    }

    /**
     * A compared Job stays compared while it has ASN rows, and the answer carries the compare on the rows left
     */
    public function deleteAsn(int $jobId, array $ids): array
    {
        $this->requireJob($jobId);
        $wasCompared = $this->asn->isReconciled($jobId);
        $deleted = $this->asn->delete($jobId, $ids);
        $stillCompared = $wasCompared && $this->asn->counts($jobId)['rows'] > 0;
        if (!$stillCompared) {
            $this->asn->setReconciled($jobId, false);
        }
        return ['deleted_count' => $deleted] + $this->asnState($jobId, $stillCompared);
    }

    public function reconcile(int $jobId, bool $force): array
    {
        $this->requireJob($jobId);
        $data = $force || $this->asn->isReconciled($jobId) ? $this->compare($jobId) : null;
        $answer = [
            'asn_filename' => $this->asn->label($jobId),
            'asn_reconciled' => (int)$this->asn->isReconciled($jobId),
            'asn_counts' => $this->asn->counts($jobId),
        ];
        return $answer + ($data === null ? ['raw_records' => $this->asn->records($jobId), 'data' => null] : ['data' => $data]);
    }

    /**
     * One row per ASN credit note number, store then number; before the Job is compared every row waits
     */
    public function dashboard(int $jobId): array
    {
        $this->requireJob($jobId);
        $compared = $this->asn->isReconciled($jobId);
        $documents = $this->verdict($jobId)['documents'];
        if (!$compared) {
            foreach ($documents as &$doc) {
                $doc['status'] = 'PENDING';
                $doc['problem'] = '';
            }
            unset($doc);
        }
        usort($documents, function (array $a, array $b): int {
            if (($a['store_no'] === null) !== ($b['store_no'] === null)) {
                return $a['store_no'] === null ? 1 : -1;
            }
            return strnatcmp((string)$a['store_no'], (string)$b['store_no']) ?: strnatcmp($a['doc_no'], $b['doc_no']);
        });
        $count = array_count_values(array_column($documents, 'status')) + ['MATCH' => 0, 'MISMATCH' => 0, 'PENDING' => 0];
        return [
            'total' => count($documents),
            'compared' => $compared,
            'complete' => $compared ? $count['MATCH'] : null,
            'error' => $compared ? $count['MISMATCH'] : null,
            'pending' => $count['PENDING'],
            'documents' => $documents,
        ];
    }

    /**
     * What every answer that changes ASN rows carries; with $reconcile the compare on the rows now, else the rows
     */
    private function asnState(int $jobId, bool $reconcile): array
    {
        $data = $reconcile ? $this->compare($jobId) : null;
        return [
            'asn_filename' => $this->asn->label($jobId),
            'asn_reconciled' => (int)$this->asn->isReconciled($jobId),
            'asn_counts' => $this->asn->counts($jobId),
            'reconcile' => $data,
            'raw_records' => $data === null ? $this->asn->records($jobId) : null,
        ];
    }

    /**
     * The compare on the current rows; a Job with ASN rows is compared from now on
     */
    private function compare(int $jobId): array
    {
        $verdict = $this->verdict($jobId);
        if ($verdict['summary'] !== null) {
            $this->asn->setReconciled($jobId, true);
        }
        return ['summary' => $verdict['summary'], 'results' => $verdict['results']];
    }

    private function verdict(int $jobId): array
    {
        return CreditNoteReconciler::reconcile($this->asn->records($jobId), $this->docs->docsWithItems($jobId));
    }

    private function requireJob(int $jobId): void
    {
        if (!$this->jobs->getJob($jobId)) {
            throw new \RuntimeException('ไม่พบ Job นี้');
        }
    }
}

<?php

namespace App;

use PDO;

class JobRepository
{
    /** @var PDO */
    private $db;

    /** @var JobFolders */
    private $folders;

    public function __construct(PDO $db, JobFolders $folders)
    {
        $this->db = $db;
        $this->folders = $folders;
    }

    /**
     * Every Job whose folder still exists, newest first, with the live counts the Job list shows
     */
    public function listJobs(): array
    {
        $stmt = $this->db->query("
            SELECT
                j.*,
                (SELECT COUNT(*) FROM job_files WHERE job_id = j.id) as live_files_count,
                (SELECT COUNT(*) FROM job_items WHERE job_id = j.id) as live_items_count,
                (SELECT COALESCE(SUM(amount), 0) FROM job_items WHERE job_id = j.id) as live_amount,
                (SELECT COUNT(*) FROM job_asn_records WHERE job_id = j.id) as asn_records_count
            FROM jobs j
            ORDER BY j.id DESC
        ");
        $jobs = array_values(array_filter($stmt->fetchAll(), function ($job) {
            return $this->folders->jobFolderExists($job);
        }));
        foreach ($jobs as &$job) {
            $job['print_file_count'] = FileManager::countFiles($this->folders->getJobPrintDir($job, false));
        }
        unset($job);
        return $jobs;
    }

    public function getJob(int $jobId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch();
        return $job ?: null;
    }

    /**
     * @return array rows of id and name, whether or not their folder exists
     */
    public function allJobNames(): array
    {
        return $this->db->query("SELECT id, name FROM jobs")->fetchAll();
    }

    /**
     * Insert the Job row and create its folders
     */
    public function insertJob(string $name): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO jobs (name, status, created_at, updated_at)
            VALUES (:name, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([':name' => $name]);
        $jobId = (int)$this->db->lastInsertId();

        $job = $this->getJob($jobId);

        $this->folders->getJobUploadDir($job);
        $this->folders->getJobFinishedDir($job);
        $this->folders->getJobPrintDir($job);
        $this->folders->getJobCnUploadDir($job);
        $this->folders->getJobCnFinishedDir($job);

        return $job;
    }

    public function deleteJobRow(int $jobId): void
    {
        $stmt = $this->db->prepare("DELETE FROM jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
    }

    /**
     * Totals and ASN state of a Job whose PDF and ASN rows were all removed
     */
    public function resetJobTotals(int $jobId): void
    {
        $stmt = $this->db->prepare("
            UPDATE jobs
            SET total_files = 0, total_items = 0, total_amount = 0,
                asn_filename = NULL, asn_matched_count = 0, asn_mismatch_count = 0,
                asn_pending_fix_count = 0, asn_reconciled = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $jobId]);
    }

    public function updateJobStats(int $jobId): void
    {
        $stmt = $this->db->prepare("
            UPDATE jobs
            SET
                total_files = (SELECT COUNT(*) FROM job_files WHERE job_id = :j1),
                total_items = (SELECT COUNT(*) FROM job_items WHERE job_id = :j2),
                total_amount = (SELECT COALESCE(SUM(amount), 0) FROM job_items WHERE job_id = :j3),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :j4
        ");
        $stmt->execute([':j1' => $jobId, ':j2' => $jobId, ':j3' => $jobId, ':j4' => $jobId]);
    }

    /**
     * @param bool $noAsnLeft the Job holds no ASN row any more, so its comparison and counts go too
     */
    public function setAsnFilename(int $jobId, ?string $summaryName, bool $noAsnLeft): void
    {
        $extraSql = $noAsnLeft ? ", asn_reconciled = 0, asn_matched_count = 0, asn_pending_fix_count = 0, asn_mismatch_count = 0" : "";
        $up = $this->db->prepare("
            UPDATE jobs
            SET asn_filename = :fn {$extraSql}, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $up->execute([':fn' => $summaryName, ':id' => $jobId]);
    }

    public function resetAsnReconciled(int $jobId): void
    {
        $resetRecon = $this->db->prepare("
            UPDATE jobs
            SET asn_reconciled = 0, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $resetRecon->execute([':id' => $jobId]);
    }

    /**
     * @param bool $hasVbrp the Job holds VBRP rows, without which a Job not compared yet waits for its VBRP file
     */
    public function initialAsnStep(array $job, bool $hasVbrp): string
    {
        if (empty($job['asn_filename'])) {
            return 'select';
        }
        if (empty($job['asn_reconciled'])) {
            return $hasVbrp ? 'compare' : 'vbrp';
        }
        if ((int)($job['asn_pending_fix_count'] ?? 0) > 0) {
            return 'fix';
        }
        if ((int)($job['asn_matched_count'] ?? 0) === 0) {
            return 'done';
        }
        return FileManager::countFiles($this->folders->getJobPrintDir($job, false)) === 0 ? 'print' : 'done';
    }
}

<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Exception;
use Classes\Services\Logger;

class IssueToTicket
{
    private PDO $pdo;
    private Logger $logger;
    private array $config;
    private int $taskId;
    private string $downloadDir;
    private string $publicBaseUrl;

    public function __construct(PDO $pdo, Logger $logger, string $env)
    {
        $this->pdo    = $pdo;
        $this->logger = $logger;

        // Carica configurazione TwProject
        $iniFile = __DIR__ . '/../../config/twproject_config.ini';
        if (!file_exists($iniFile)) {
            throw new Exception("File twproject_config.ini non trovato: {$iniFile}");
        }

        $section = "twprj_{$env}";
        $this->config = parse_ini_file($iniFile, true)[$section] ?? null;

        if (!$this->config) {
            throw new Exception("Sezione '{$section}' non definita in twproject_config.ini");
        }

        // Imposta taskId e aggiorna il logger
        $this->taskId = (int)$this->config['task_issue_to_ticket'];
        $this->logger->setTask('IssueToTicket');

        // Imposta percorsi
        $this->downloadDir   = rtrim($this->config['resource_local_path'], '/');
        $this->publicBaseUrl = rtrim($this->config['public_url_attachments'], '/') . '/';
    }

    public function run(): void
    {
        $this->logger->info("=== Inizio sincronizzazione IssueToTicket ===");

        $apiKey = $this->config['key'];
        $apiUrl = $this->config['url'];

        $payload = [
            "command" => "list",
            "object"  => "issue",
            "filters" => [
                "status" => "1",
                "taskId" => (string)$this->taskId
            ],
            "pageSize" => 50,
            "page" => 1,
            "orderBy" => "lastStatusChangeDate"
        ];

        $this->logger->info("DEBUG payload API: " . json_encode($payload));

        $issues = $this->callTwprojectApi($payload, $apiKey, $apiUrl);

        $this->logger->info("DEBUG risposta API: " . json_encode($issues));

        if (empty($issues['objects'])) {
            $this->logger->info("Nessuna issue aperta trovata per task {$this->taskId}");
            return;
        }

        foreach ($issues['objects'] as $issue) {
            $this->processIssue($issue, $apiKey, $apiUrl);
        }

        $this->logger->info("=== Fine sincronizzazione IssueToTicket ===");
    }

    private function processIssue(array $issue, string $apiKey, string $apiUrl): void
    {
        $idIssue     = (int)$issue['id'];
        $creator     = $issue['creator'] ?? '';
        $subject     = $issue['subject'] ?? '';
        $description = $issue['description'] ?? '';

        // Controlla duplicati in audit
        $stmtCheck = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit.control_issue_to_ticket WHERE id_issue = :id_issue"
        );
        $stmtCheck->execute([':id_issue' => $idIssue]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            $this->logger->info("Issue {$idIssue} già presente in audit.control_issue_to_ticket");
            return;
        }

        $aliasIdIssue = "#TODO{$idIssue}";

        // Inserisce ticket in segn.segn_id
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO segn.segn_id (
                seg_t_mat, seg_o_ogg, seg_o_desc, seg_s_ac_op, 
                office_manager, seg_i_rek_alias, seg_i_ty_cont,
                isFrom
            ) VALUES (
                :mat, :ogg, :desc, :op, 
                'Ufficio Manutenzioni e Viabilità', :aliasIdIssue, 'TwProject',
                'twproject'
            ) RETURNING seg_id
        ");
        $stmtInsert->execute([
            ':mat'          => 'Attività preventive',
            ':ogg'          => $subject,
            ':desc'         => $description,
            ':op'           => $creator,
            ':aliasIdIssue' => $aliasIdIssue
        ]);
        $idTicket = (int)$stmtInsert->fetchColumn();
        $this->logger->info("Inserito ticket {$idTicket} per issue {$idIssue}");

        // Download allegati
        try {
            $files = $this->downloadIssueDocuments($issue);
            $this->insertAttachments($idTicket, $files);
        } catch (Exception $e) {
            $this->logger->error("Errore nel download dei documenti per issue {$idIssue}: {$e->getMessage()}");
        }

        // Aggiorna audit
        $stmtAudit = $this->pdo->prepare("
            INSERT INTO audit.control_issue_to_ticket (id_issue, id_ticket, status_id)
            VALUES (:id_issue, :id_ticket, 1)
        ");
        $stmtAudit->execute([
            ':id_issue'  => $idIssue,
            ':id_ticket' => $idTicket
        ]);
    }

    private function insertAttachments(int $idTicket, array $files): void
    {
        if (empty($files)) {
            $this->logger->info("Nessun documento trovato per ticket {$idTicket}");
            return;
        }

        $stmtDoc = $this->pdo->prepare("
            INSERT INTO segn.issue_docs_attachment (id_ticket, url, file_name)
            VALUES (:id_ticket, :url, :file_name)
        ");

        foreach ($files as $file) {
            $publicUrl = $this->publicBaseUrl . rawurlencode($file['name']);
            $stmtDoc->execute([
                ':id_ticket' => $idTicket,
                ':url'       => $publicUrl,
                ':file_name' => $file['name']
            ]);
            $this->logger->info("File {$file['name']} scaricato e inserito in DB come URL: {$publicUrl}");
        }
    }

    private function callTwprojectApi(array $data, string $apiKey, string $apiUrl): array
    {
        $data['APIKey'] = $apiKey;

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->logger->error("Errore cURL: " . curl_error($ch));
            curl_close($ch);
            return [];
        }

        curl_close($ch);
        $decoded = json_decode($response, true);

        if (!$decoded) {
            $this->logger->error("Errore decoding JSON API: " . $response);
            return [];
        }

        return $decoded;
    }

    private function downloadIssueDocuments(array $issue): array
    {
        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0777, true);
        }

        $documents = $issue['documents'] ?? [];
        $filesLocal = [];

        foreach ($documents as $doc) {
            if (empty($doc['url']) || empty($doc['name'])) {
                continue;
            }

            $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['name']);
            $savePath = $this->downloadDir . '/' . $fileName;

            try {
                $this->downloadFile($doc['url'], $savePath);
                $filesLocal[] = ['name' => $fileName, 'path' => $savePath];
            } catch (Exception $e) {
                $this->logger->error("Errore download file {$fileName}: {$e->getMessage()}");
            }
        }

        return $filesLocal;
    }

    private function downloadFile(string $url, string $savePath): void
    {
        $fp = fopen($savePath, 'w');
        if (!$fp) {
            throw new Exception("Impossibile creare file {$savePath}");
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);

        if (curl_errno($ch)) {
            fclose($fp);
            throw new Exception("Errore download file: " . curl_error($ch));
        }

        curl_close($ch);
        fclose($fp);
    }
}

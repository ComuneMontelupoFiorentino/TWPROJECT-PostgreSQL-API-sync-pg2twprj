<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Classes\Services\Logger;
use RuntimeException;

class SyncStatusPgToTwprj
{
    private PDO $pdo;
    private Logger $logger;
    private string $apiUrl;
    private string $apiKey;
    private string $appEnv;

    public function __construct(PDO $pdo, Logger $logger, string $appEnv, string $apiUrl, string $apiKey)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->appEnv = $appEnv;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
        $this->apiKey = $apiKey;

        $this->logger->setTask('SyncStatusPgToTwprj');
    }

    /**
     * Sincronizza tutti gli status dei todo da PostgreSQL verso Twproject
     */
    public function run(): void
    {
        $this->logger->info("=== Inizio sinc status ===");

        $page = 1;
        $pageSize = 200;
        $twIssues = [];

        // Recupero tutti gli issue aperti da Twproject
        do {
            $apiData = [
                "command" => "list",
                "object"  => "issue",
                "filters" => ["taskId" => "3632", "status" => "1"],
                "pageSize" => $pageSize,
                "page" => $page,
                "orderBy" => "taskName"
            ];

            $response = $this->callTwprojectApi($apiData);
            if (!$response || empty($response['objects'])) {
                break;
            }

            $twIssues = array_merge($twIssues, $response['objects']);
            $page++;
        } while (count($response['objects']) === $pageSize);

        if (empty($twIssues)) {
            $this->logger->info("Nessun issue aperto trovato su Twproject");
            return;
        }

        foreach ($twIssues as $issue) {
            $idIssue = (int)$issue['id'];

            // Recupero record audit
            $stmt = $this->pdo->prepare(
                "SELECT status_id, sync FROM audit.control_issue_to_ticket WHERE id_issue = :id_issue"
            );
            $stmt->execute([':id_issue' => $idIssue]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $this->logger->info("Disallineamenti: Issue $idIssue eliminato su ProSIT o non inserito");
                continue; // Nessun record audit → skip
            }

            $statusId = (int)$record['status_id'];
            $sync = (bool)$record['sync'];

            if ($statusId === 1) {
                // Issue già aperto → nulla
                continue;
            }

            if ($statusId === 2 && $sync === true) {
                // Twproject vince → riapriamo audit
                $stmtUpdate = $this->pdo->prepare(
                    "UPDATE audit.control_issue_to_ticket SET status_id = 1, sync = false WHERE id_issue = :id_issue"
                );
                $stmtUpdate->execute([':id_issue' => $idIssue]);
                $this->logger->info("Riaperto issue $idIssue in audit (Twproject vince)");
            }

            if ($statusId === 2 && $sync === false) {
                // Prosit vince → chiudiamo issue su Twproject
                $issueData = [
                    "command" => "update",
                    "object"  => "issue",
                    "id"      => $idIssue,
                    "data"    => ["statusId" => 2],
                    "APIKey"  => $this->apiKey
                ];

                $apiResponse = $this->callTwprojectApi($issueData);
                $statusUpdated = $apiResponse['object']['statusId'] ?? null;

                if ($apiResponse && $statusUpdated === 2) {
                    $stmtUpdate = $this->pdo->prepare(
                        "UPDATE audit.control_issue_to_ticket SET sync = true WHERE id_issue = :id_issue"
                    );
                    $stmtUpdate->execute([':id_issue' => $idIssue]);
                    $this->logger->info("Aggiornato issue $idIssue su Twproject e audit (Prosit vince)");
                } else {
                    $this->logger->error("Errore aggiornamento issue $idIssue su Twproject (statusId=$statusUpdated)");
                }
            }
        }

        $this->logger->info("=== Fine sinc status ===");
    }

    /**
     * Chiamata API a Twproject
     */
    private function callTwprojectApi(array $data): ?array
    {
        $data['APIKey'] = $this->apiKey;

        $ch = curl_init($this->apiUrl);
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
            return null;
        }
        curl_close($ch);

        return json_decode($response, true);
    }
}

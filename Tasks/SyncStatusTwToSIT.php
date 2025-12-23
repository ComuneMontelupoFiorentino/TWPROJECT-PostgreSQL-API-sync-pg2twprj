<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Classes\Services\Logger;
use RuntimeException;

class SyncStatusTwToSIT
{
    private PDO $pdo;
    private Logger $logger;
    private string $apiUrl;
    private string $apiKey;
    private string $appEnv;

    public function __construct(PDO $pdo, Logger $logger, string $appEnv, string $apiUrl, string $apiKey)
    {
        $this->pdo    = $pdo;
        $this->logger = $logger;
        $this->appEnv = $appEnv;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
        $this->apiKey = $apiKey;

        $this->logger->setTask('SyncStatusTwToSIT');
    }

    /**
     * Funzione principale per sincronizzare lo stato dei todo da Twproject a PostgreSQL
     */
    public function run(): void
    {
        $this->logger->info("=== Inizio sinc status Twproject → SIT ===");

        $stmt = $this->pdo->query("
            SELECT id_todo_twprj 
            FROM audit.todo_queue 
            WHERE status = 'sended' 
              AND todo_status_twprj = 1
            ORDER BY id ASC
        ");

        $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$todos) {
            $this->logger->info("Nessuna todo da sincronizzare");
            $this->logger->info("=== Fine sinc status Twproject → SIT ===");
            return;
        }

        foreach ($todos as $row) {
            $idIssue = (int)$row['id_todo_twprj'];

            // Recupero dati issue
            $issueData = [
                "command" => "get",
                "object"  => "issue",
                "id"      => $idIssue
            ];

            $response = $this->callTwprojectApi($issueData);

            if (!$response || !isset($response['object']['statusId'])) {
                $this->logger->error("Errore recupero issue $idIssue");
                continue;
            }

            $statusId = (int)$response['object']['statusId'];

            if ($statusId === 2 || $statusId === 3) {
                // Recupero commenti
                $commentsData = [
                    "command" => "getComments",
                    "object"  => "issue",
                    "id"      => $idIssue
                ];
                $commentsResponse = $this->callTwprojectApi($commentsData);

                $formattedComments = [];
                if (
                    $commentsResponse &&
                    isset($commentsResponse['comments']) &&
                    is_array($commentsResponse['comments']) &&
                    count($commentsResponse['comments']) > 0
                ) {
                    usort($commentsResponse['comments'], fn($a,$b) => $a['creationDate'] <=> $b['creationDate']);
                    foreach ($commentsResponse['comments'] as $comment) {
                        $timestamp = (int) round($comment['creationDate'] / 1000);
                        $date = date('Y-m-d H:i:s', $timestamp);
                        $formattedComments[] = [
                            "date"    => $date,
                            "creator" => $comment['creator'],
                            "comment" => trim($comment['comment'])
                        ];
                    }
                }

                // Aggiorna tabella audit.todo_queue
                $stmtUpdate = $this->pdo->prepare("
                    UPDATE audit.todo_queue 
                    SET todo_status_twprj = :status,
                        sinc_status = NOW(),
                        todo_comments_twprj = :comments
                    WHERE id_todo_twprj = :id_todo
                ");

                $stmtUpdate->execute([
                    ':status'   => $statusId,
                    ':comments' => json_encode($formattedComments, JSON_UNESCAPED_UNICODE),
                    ':id_todo'  => $idIssue
                ]);

                $this->logger->info("Aggiornato todo_queue per issue $idIssue");

            } else {
                $this->logger->info("Issue $idIssue ancora aperta (statusId = $statusId)");
            }
        }

        $this->logger->info("=== Fine sinc status Twproject → SIT ===");
    }

    /**
     * Funzione helper per chiamare Twproject API
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

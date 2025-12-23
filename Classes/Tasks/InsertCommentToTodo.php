<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Classes\Services\Logger;
use RuntimeException;

class InsertCommentToTodo
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
        $this->logger->setTask('InsertCommentToTodo');
    }

    /**
     * Invia tutti i commenti pendenti dalla tabella reporting_ticket
     */
    public function runFromQueue(): void
    {
        $this->logger->info("=== Inizio sinc comments ===");

        $stmt = $this->pdo->query("
            SELECT 
                rt.id,
                rt.action_user || ': ' || COALESCE(rt.action_description,'') AS comment,
                cit.id_issue
            FROM segn.reporting_ticket AS rt
            JOIN audit.control_issue_to_ticket AS cit
                ON rt.id_prosit = cit.id_ticket
            WHERE rt.is_from = 'twproject'
            AND rt.action_description IS NOT NULL
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $comment = (string)($row['comment'] ?? '');
            $this->processComment((int)$row['id'], (int)$row['id_issue'], $comment, false);
        }

        $this->logger->info("=== Fine sinc comments ===");
    }

    /**
     * Invia un singolo commento passato da CLI
     */
    public function runFromCli(array $options): void
    {
        if (empty($options['idIssue']) || empty($options['comment'])) {
            throw new RuntimeException("Parametri obbligatori mancanti: --idIssue e --comment");
        }

        $idIssue = (int)$options['idIssue'];
        $comment = (string)$options['comment'];

        $this->logger->info("=== Inizio invio commento singolo ===");
        $this->processComment(null, $idIssue, $comment, true);
        $this->logger->info("=== Fine invio commento singolo ===");
    }

    /**
     * Funzione che invia il commento a TWProject
     *
     * @param int|null $idReportingTicket ID del record nella tabella reporting_ticket, se presente
     * @param int $idIssue ID dell'issue Twproject
     * @param string $comment Testo del commento
     * @param bool $cli Se true, indica che il commento viene inviato da CLI (non elimina record)
     */
    private function processComment(?int $idReportingTicket, int $idIssue, string $comment, bool $cli = false): void
    {
        $payload = [
            "command" => "addComment",
            "object"  => "issue",
            "id"      => $idIssue,
            "comment" => $comment,
            "APIKey"  => $this->apiKey
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error("Errore cURL: " . curl_error($ch));
            curl_close($ch);
            return;
        }
        curl_close($ch);

        $responseData = json_decode($response, true);
        if (!empty($responseData['ok']) && $responseData['ok'] === true) {
            $this->logger->info("Commento inviato per issue ID $idIssue");

            // Se non è un commento CLI, elimina il record dalla tabella
            if (!$cli && $idReportingTicket !== null) {
                $stmt = $this->pdo->prepare("DELETE FROM segn.reporting_ticket WHERE id = :id");
                $stmt->execute([':id' => $idReportingTicket]);
                $this->logger->info("Commento eliminato da reporting_ticket (id=$idReportingTicket)");
            }
        } else {
            $this->logger->error("Invio commento fallito per issue ID=$idIssue. Risposta API: " . json_encode($responseData));
        }
    }
}

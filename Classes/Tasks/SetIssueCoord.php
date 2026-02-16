<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Classes\Services\Logger;
use RuntimeException;

class SetIssueCoord
{
    private PDO $pdo;
    private Logger $logger;
    private string $apiUrl;
    private string $apiKey;

    public function __construct(PDO $pdo, Logger $logger, string $appEnvironment)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;

        $iniFile = __DIR__ . '/../../config/twproject_config.ini';
        if (!file_exists($iniFile)) {
            throw new RuntimeException("File twproject_config.ini non trovato");
        }

        $section = "twprj_{$appEnvironment}";
        $ini = parse_ini_file($iniFile, true);

        if (empty($ini[$section])) {
            throw new RuntimeException("Sezione {$section} non presente");
        }

        $this->apiUrl = rtrim($ini[$section]['url'], '/') . '/';
        $this->apiKey = $ini[$section]['key'];

        $this->logger->setTask('SetIssueCoord');
    }

    /* ============================================================
       MODALITÀ DIRETTA (issueId + lat + lon)
    ============================================================ */
    public function setCoordinates(int $issueId, float $lat, float $lon): bool
    {
        // Controllo numeri finiti
        if (!is_finite($lat) || !is_finite($lon)) {
            $this->logger->error("Lat/Lon non finite per issue $issueId: lat=$lat lon=$lon");
            return false;
        }

        try {
            $success = $this->callSetCoordsApi($issueId, $lat, $lon);

            if ($success) {
                $this->logger->info("Coordinate assegnate a issue $issueId");
            } else {
                $this->logger->error("Fallita assegnazione coordinate issue $issueId");
            }

            return $success;

        } catch (\Throwable $e) {
            $this->logger->error("Errore setCoordinates: " . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       MODALITÀ DA DATABASE (PK tabella)
    ============================================================ */
    public function setCoordinatesFromDb(int $recordId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id_todo_twprj, lat, lon
            FROM audit.todo_queue
            WHERE id = :id
        ");

        $stmt->execute([':id' => $recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->logger->error("Record $recordId non trovato");
            return false;
        }

        if (empty($row['id_todo_twprj']) || $row['lat'] === null || $row['lon'] === null) {
            $this->logger->error("Coordinate o issueId mancanti per record $recordId");
            return false;
        }

        return $this->setCoordinates(
            (int)$row['id_todo_twprj'],
            (float)$row['lat'],
            (float)$row['lon']
        );
    }

    /* ============================================================
       CHIAMATA API TWPROJECT
    ============================================================ */
    private function callSetCoordsApi(int $issueId, float $lat, float $lon): bool
    {
        $payload = [
            "command" => "setJSONData",
            "object"  => "issue",
            "id"      => $issueId,
            "data"    => [
                "coords" => [
                    "latitude" => $lat,
                    "longitude" => $lon,
                    "accuracy" => 100  // default richiesto da Twproject
                ]
            ],
            "APIKey"  => $this->apiKey
        ];

        // Maschera APIKey nei log
        $logPayload = $payload;
        $logPayload['APIKey'] = '***';
        $this->logger->info("Payload coords: " . json_encode($logPayload, JSON_PRETTY_PRINT));

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 15
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->logger->error("Errore cURL (coords): " . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $responseData = json_decode($response, true);

        if (empty($responseData) || (!isset($responseData['ok']) && !isset($responseData['object']))) {
            $this->logger->error("Risposta inattesa Twproject: " . $response);
            return false;
        }

        return true;
    }
}

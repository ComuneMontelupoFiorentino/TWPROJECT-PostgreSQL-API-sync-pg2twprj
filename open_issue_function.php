<?php
declare(strict_types=1);

/**
 * Crea un issue su Twproject e opzionalmente aggiunge le coordinate.
 *
 * @param array $todoRecord riga della tabella audit.todo_queue
 * @param string $apiKey chiave API Twproject
 * @return int|false ID dell'issue creato oppure false se errore
 * 
 * Created by Comune di Montelupo Fiorentino
 * Ufficio Supporto alla transizione digitale
 * Funzionario Enrico Gullì
 * 19-09-25
 */
function sendTodoFromQueue(array $todoRecord, string $apiKey): int|false
{
    $url = 'https://mfnotwpro.ddns.net:8443/API/V1/';

    try {
        // 1. Creazione issue
        $data = [
            "command" => "create",
            "object"  => "issue",
            "data"    => [
                "subject"        => $todoRecord['subject'],
                "description"    => $todoRecord['body'],
                "taskId"         => (int)$todoRecord['task_id'],
                "gravity"        => $todoRecord['gravity'],
                "signalledOnDate"=> date('d/m/Y'), // oggi o valorizzalo come vuoi
                "tags"           => "Manutenzione, Prosit, segnalazioni",
                "shouldCloseBy"  => strtotime("+7 days") * 1000, // esempio
                "assignedById"   => (int)$todoRecord['assigned_by'],
                "assigneeId"     => $todoRecord['assignee_id'], // da mappare se serve
                "typeId"         => 51
            ],
            "APIKey"  => $apiKey
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception("Errore cURL (issue): " . curl_error($ch));
        }
        curl_close($ch);

        $responseData = json_decode($response, true);
        if (!isset($responseData['object']['id'])) {
            throw new Exception("ID issue non trovato. Risposta: " . $response);
        }

        $issueId = (int)$responseData['object']['id'];

        // 2. Se lat/lon sono valorizzati aggiungo coordinate
        if (!empty($todoRecord['lat']) && !empty($todoRecord['lon'])) {
            $coordsData = [
                "command" => "setJSONData",
                "object"  => "issue",
                "id"      => $issueId,
                "data"    => [
                    "coords" => [
                        "latitude"  => (float)$todoRecord['lat'],
                        "longitude" => (float)$todoRecord['lon'],
                        "accuracy"  => 100
                    ]
                ],
                "APIKey" => $apiKey
            ];

            $chCoords = curl_init($url);
            curl_setopt($chCoords, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCoords, CURLOPT_POST, true);
            curl_setopt($chCoords, CURLOPT_POSTFIELDS, json_encode($coordsData));
            curl_setopt($chCoords, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $coordsResponse = curl_exec($chCoords);
            if (curl_errno($chCoords)) {
                throw new Exception("Errore cURL (coords): " . curl_error($chCoords));
            }
            curl_close($chCoords);

            $coordsResult = json_decode($coordsResponse, true);
            if (!isset($coordsResult['ok']) || $coordsResult['ok'] !== true) {
                throw new Exception("Errore invio coordinate. Risposta: " . $coordsResponse);
            }
        }

        return $issueId;
    } catch (Exception $e) {
        error_log("[sendTodoFromQueue] Errore: " . $e->getMessage());
        return false;
    }
}

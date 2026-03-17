<?php

declare(strict_types=1);

class VoiceAssistantGateway extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties for Secrets and Default Routing
        $this->RegisterPropertyString('OpenAIKey', '');
        $this->RegisterPropertyString('ElevenLabsKey', '');
        $this->RegisterPropertyInteger('DefaultCharacter_ID', 0);
        
        // Konfigurierbares LLM
        $this->RegisterPropertyString('LLM_Base_URL', 'https://api.openai.com/v1/chat/completions');
        $this->RegisterPropertyString('LLM_Model', 'gpt-4o-mini');

        // Status Variables for Gateway
        $this->RegisterVariableString('LastSpokenText', 'Last Spoken Text', '', 10);
        $this->RegisterVariableInteger('LastMediaID', 'Last Media ID', '', 20);
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Konfiguration validieren
        $openAIKey = $this->ReadPropertyString('OpenAIKey');
        $elevenLabsKey = $this->ReadPropertyString('ElevenLabsKey');
        $llmUrl = $this->ReadPropertyString('LLM_Base_URL');

        if (empty($openAIKey) || empty($elevenLabsKey) || empty($llmUrl)) {
            $this->SetStatus(200); // Konfiguration unvollständig
            $missing = [];
            if (empty($openAIKey)) $missing[] = 'OpenAI Key';
            if (empty($elevenLabsKey)) $missing[] = 'ElevenLabs Key';
            if (empty($llmUrl)) $missing[] = 'LLM Base URL';
            $this->SendDebug('ApplyChanges', '⚠️ Fehlende Konfiguration: ' . implode(', ', $missing), 0);
        } else {
            $this->SetStatus(102); // Aktiv
            $this->SendDebug('ApplyChanges', '✅ Gateway konfiguriert. LLM URL: ' . $llmUrl . ' | Modell: ' . $this->ReadPropertyString('LLM_Model'), 0);
        }
    }

    // ─────────────────────────────────────────
    //  DataFlow: ForwardData (Child -> Parent)
    // ─────────────────────────────────────────

    /**
     * DataFlow Entrance point (ForwardData)
     * Empfängt Daten von Child-Instanzen (SendDataToParent) AUFWÄRTS.
     */
    public function ForwardData($JSONString)
    {
        $this->SendDebug('┌─ ForwardData', '═══════════════════════════════════════', 0);
        $this->SendDebug('│ ForwardData', 'Empfangener JSON-String (Länge: ' . strlen($JSONString) . ' Bytes)', 0);
        $this->SendDebug('│ ForwardData', $JSONString, 0);

        $data = json_decode($JSONString, true);
        
        if ($data === null) {
            $this->SendDebug('│ ForwardData ❌', 'JSON Parsing fehlgeschlagen! json_last_error: ' . json_last_error_msg(), 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        if (!isset($data['DataID'])) {
            $this->SendDebug('│ ForwardData ❌', 'Kein DataID-Feld im JSON gefunden. Vorhandene Keys: ' . implode(', ', array_keys($data)), 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        $this->SendDebug('│ ForwardData', 'DataID: ' . $data['DataID'], 0);

        if ($data['DataID'] !== '{E6892CCF-7622-4217-9150-C1DE886296DD}') {
            $this->SendDebug('│ ForwardData ❌', 'DataID-Mismatch! Erwartet: {E6892CCF-7622-4217-9150-C1DE886296DD} (eigene implemented-GUID)', 0);
            $this->SendDebug('│ ForwardData ❌', 'Erhalten: ' . $data['DataID'], 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        if (!isset($data['Function'])) {
            $this->SendDebug('│ ForwardData ❌', 'Kein Function-Feld im JSON. Buffer: ' . json_encode($data), 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        $function = $data['Function'];
        $buffer = isset($data['Buffer']) ? $data['Buffer'] : [];
        $this->SendDebug('│ ForwardData', '→ Function: ' . $function, 0);
        $this->SendDebug('│ ForwardData', '→ Buffer Keys: ' . implode(', ', array_keys($buffer)), 0);

        $result = '';
        switch ($function) {
            case 'ForwardToLLM':
                $this->SendDebug('│ ForwardData', '▶ Rufe ForwardToLLM auf...', 0);
                $result = $this->ForwardToLLM($buffer['SystemPrompt'], $buffer['BaseText'], $buffer['EventName']);
                $this->SendDebug('│ ForwardData', '◀ LLM Ergebnis-Länge: ' . strlen($result) . ' Zeichen', 0);
                break;
            case 'ForwardToElevenLabs':
                $this->SendDebug('│ ForwardData', '▶ Rufe ForwardToElevenLabs auf...', 0);
                $rawAudio = $this->ForwardToElevenLabs($buffer['Text'], $buffer['VoiceID'], $buffer['ModelID']);
                $this->SendDebug('│ ForwardData', '◀ TTS Roh-Audio: ' . strlen($rawAudio) . ' Bytes', 0);
                // WICHTIG: Binäre Audio-Daten MÜSSEN base64-kodiert werden,
                // da der DataFlow-Rückkanal keine Binärdaten transportieren kann!
                if (!empty($rawAudio)) {
                    $result = base64_encode($rawAudio);
                    $this->SendDebug('│ ForwardData', '◀ Base64-kodiert: ' . strlen($result) . ' Zeichen', 0);
                }
                break;
            default:
                $this->SendDebug('│ ForwardData ❌', 'Unbekannte Funktion: ' . $function, 0);
                break;
        }

        $this->SendDebug('│ ForwardData', empty($result) ? '❌ Ergebnis LEER' : '✅ Ergebnis vorhanden', 0);
        $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
        return $result;
    }

    // ─────────────────────────────────────────
    //  Public Speak (Gateway-Level)
    // ─────────────────────────────────────────

    public function Speak(string $EventName, string $BaseText): int
    {
        $this->SendDebug('Speak', "Event: $EventName | Text: $BaseText", 0);
        $defaultCharacterId = $this->ReadPropertyInteger('DefaultCharacter_ID');

        if ($defaultCharacterId == 0 || !IPS_InstanceExists($defaultCharacterId)) {
            $this->SendDebug('Speak ❌', 'Kein gültiger Default-Charakter. ID: ' . $defaultCharacterId, 0);
            IPS_LogMessage('VoiceAssistantGateway', 'Die Speak-Funktion wurde aufgerufen, aber es ist kein gültiger Standard-Charakter hinterlegt (ID: ' . $defaultCharacterId . ').');
            return 0;
        }

        $this->SendDebug('Speak', '→ Leite weiter an IVD_Speak (Charakter-ID: ' . $defaultCharacterId . ')', 0);
        $mediaId = IVD_Speak($defaultCharacterId, $EventName, $BaseText);
        $this->SendDebug('Speak', '← IVD_Speak Ergebnis: MediaID = ' . $mediaId, 0);

        if ($mediaId > 0) {
            $this->SetValue('LastSpokenText', $BaseText);
            $this->SetValue('LastMediaID', $mediaId);
            $this->SendDebug('Speak ✅', 'Statusvariablen aktualisiert.', 0);
        }

        return $mediaId;
    }

    // ─────────────────────────────────────────
    //  ForwardToLLM
    // ─────────────────────────────────────────

    public function ForwardToLLM(string $SystemPrompt, string $BaseText, string $EventName): string
    {
        $this->SendDebug('┌─ ForwardToLLM', '═══════════════════════════════════════', 0);
        $apiKey = $this->ReadPropertyString('OpenAIKey');
        $url = $this->ReadPropertyString('LLM_Base_URL');
        $model = $this->ReadPropertyString('LLM_Model');
        
        $this->SendDebug('│ ForwardToLLM', 'URL: ' . $url, 0);
        $this->SendDebug('│ ForwardToLLM', 'Modell: ' . $model, 0);
        $this->SendDebug('│ ForwardToLLM', 'API Key vorhanden: ' . (!empty($apiKey) ? 'Ja (' . strlen($apiKey) . ' Zeichen)' : '❌ NEIN'), 0);
        $this->SendDebug('│ ForwardToLLM', 'SystemPrompt: ' . $SystemPrompt, 0);
        $this->SendDebug('│ ForwardToLLM', 'BaseText: ' . $BaseText, 0);
        $this->SendDebug('│ ForwardToLLM', 'EventName: ' . $EventName, 0);

        if (empty($url)) {
            $this->SendDebug('│ ForwardToLLM ❌', 'LLM Base URL ist leer!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'ForwardToLLM: LLM Base URL ist leer.');
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $SystemPrompt . "\n\nWICHTIG: Antworte immer mit Text, der ElevenLabs Emotion-Tags in englisch und in eckigen Klammern enthält (z.B. [laughs])."],
                ['role' => 'user', 'content' => "Ereignis: $EventName. Text: $BaseText"]
            ],
            'temperature' => 0.7
        ];

        $requestBody = json_encode($data);
        $this->SendDebug('│ ForwardToLLM', 'Request Body (Länge: ' . strlen($requestBody) . '): ' . substr($requestBody, 0, 500), 0);

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $this->SendDebug('│ ForwardToLLM', 'Sende cURL Request an ' . $url . '...', 0);
        $startTime = microtime(true);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $elapsed = round((microtime(true) - $startTime) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->SendDebug('│ ForwardToLLM', "HTTP Status: $httpCode | Dauer: {$elapsed}ms", 0);

        if ($response === false) {
            $this->SendDebug('│ ForwardToLLM ❌', "cURL Fehler #$errno: $error", 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToLLM: cURL Fehler #$errno: $error");
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        if ($httpCode >= 400) {
            $this->SendDebug('│ ForwardToLLM ❌', "HTTP Fehler $httpCode", 0);
            $this->SendDebug('│ ForwardToLLM ❌', 'Response: ' . substr($response, 0, 1000), 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToLLM: HTTP $httpCode - " . substr($response, 0, 500));
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        $this->SendDebug('│ ForwardToLLM', 'Response (Länge: ' . strlen($response) . '): ' . substr($response, 0, 500), 0);

        $responseData = json_decode($response, true);
        if ($responseData === null) {
            $this->SendDebug('│ ForwardToLLM ❌', 'JSON Parsing der Antwort fehlgeschlagen: ' . json_last_error_msg(), 0);
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        if (isset($responseData['choices'][0]['message']['content'])) {
            $result = trim($responseData['choices'][0]['message']['content']);
            $this->SendDebug('│ ForwardToLLM ✅', 'Generierter Text: ' . $result, 0);
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return $result;
        }

        $this->SendDebug('│ ForwardToLLM ❌', 'Kein choices[0].message.content in der Antwort. Keys: ' . implode(', ', array_keys($responseData)), 0);
        if (isset($responseData['error'])) {
            $this->SendDebug('│ ForwardToLLM ❌', 'API Error: ' . json_encode($responseData['error']), 0);
        }
        $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
        return '';
    }

    // ─────────────────────────────────────────
    //  ForwardToElevenLabs
    // ─────────────────────────────────────────

    public function ForwardToElevenLabs(string $Text, string $VoiceID, string $ModelID): string
    {
        $this->SendDebug('┌─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
        $apiKey = $this->ReadPropertyString('ElevenLabsKey');
        
        $this->SendDebug('│ ForwardToElevenLabs', 'API Key vorhanden: ' . (!empty($apiKey) ? 'Ja (' . strlen($apiKey) . ' Zeichen)' : '❌ NEIN'), 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'VoiceID: ' . (empty($VoiceID) ? '❌ LEER' : $VoiceID), 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'ModelID: ' . (empty($ModelID) ? '(default wird verwendet)' : $ModelID), 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'Text (Vorschau): ' . substr($Text, 0, 100) . (strlen($Text) > 100 ? '...' : ''), 0);

        if (empty($apiKey)) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', 'ElevenLabs API Key ist leer!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'ForwardToElevenLabs: API Key ist leer.');
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        if (empty($VoiceID)) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', 'Voice ID ist leer!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'ForwardToElevenLabs: Voice ID ist leer.');
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        if (empty($ModelID)) {
            $ModelID = 'eleven_multilingual_v2';
            $this->SendDebug('│ ForwardToElevenLabs', 'Kein Model angegeben, verwende Default: ' . $ModelID, 0);
        }

        $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $VoiceID;
        $data = [
            'text' => $Text,
            'model_id' => $ModelID,
        ];

        $requestBody = json_encode($data);
        $this->SendDebug('│ ForwardToElevenLabs', 'URL: ' . $url, 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'Request Body: ' . $requestBody, 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'Sende cURL Request...', 0);

        $startTime = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'xi-api-key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $elapsed = round((microtime(true) - $startTime) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $this->SendDebug('│ ForwardToElevenLabs', "HTTP Status: $httpCode | Dauer: {$elapsed}ms | Content-Type: $contentType", 0);

        if ($response === false) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', "cURL Fehler #$errno: $error", 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToElevenLabs: cURL Fehler #$errno: $error");
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        if ($httpCode >= 400) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', "HTTP Fehler $httpCode", 0);
            $this->SendDebug('│ ForwardToElevenLabs ❌', 'Response: ' . substr($response, 0, 1000), 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToElevenLabs: HTTP $httpCode - " . substr($response, 0, 500));
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        $responseLen = strlen($response);
        $this->SendDebug('│ ForwardToElevenLabs ✅', "Audio empfangen: $responseLen Bytes", 0);
        $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
        return $response;
    }

    // ─────────────────────────────────────────
    //  Test-Funktionen (für form.json Actions)
    // ─────────────────────────────────────────

    /**
     * Test: LLM API isoliert testen
     */
    public function TestLLM(string $SystemPrompt, string $BaseText, string $EventName): string
    {
        $this->SendDebug('TestLLM', '═══ MANUELLER LLM-TEST GESTARTET ═══', 0);
        $result = $this->ForwardToLLM($SystemPrompt, $BaseText, $EventName);

        if (empty($result)) {
            return "❌ LLM-Test fehlgeschlagen!\n\nBitte prüfe:\n• OpenAI API Key hinterlegt?\n• LLM Base URL korrekt?\n• Modell verfügbar?\n\n→ Details im Debug-Log dieser Instanz.";
        }

        return "✅ LLM-Test erfolgreich!\n\n📝 Generierter Text:\n" . $result;
    }

    /**
     * Test: ElevenLabs TTS isoliert testen
     */
    public function TestTTS(string $Text, string $VoiceID, string $ModelID): string
    {
        $this->SendDebug('TestTTS', '═══ MANUELLER TTS-TEST GESTARTET ═══', 0);

        if (empty($VoiceID)) {
            return "❌ Voice ID ist leer! Bitte eine ElevenLabs Voice ID eingeben.";
        }

        $result = $this->ForwardToElevenLabs($Text, $VoiceID, $ModelID);

        if (empty($result)) {
            return "❌ TTS-Test fehlgeschlagen!\n\nBitte prüfe:\n• ElevenLabs API Key hinterlegt?\n• Voice ID gültig?\n• Modell korrekt?\n\n→ Details im Debug-Log dieser Instanz.";
        }

        return "✅ TTS-Test erfolgreich!\n\n🔊 Audio empfangen: " . strlen($result) . " Bytes\n\n(Audio-Stream wurde nicht gespeichert – dies war nur ein Verbindungstest.)";
    }

    /**
     * Test: Vollständiger Speak-Durchlauf über Default-Charakter
     */
    public function TestSpeak(string $EventName, string $BaseText): string
    {
        $this->SendDebug('TestSpeak', '═══ MANUELLER SPEAK-TEST GESTARTET ═══', 0);
        $mediaId = $this->Speak($EventName, $BaseText);

        if ($mediaId > 0) {
            return "✅ Speak-Test erfolgreich!\n\n🎵 Media-ID: " . $mediaId . "\n📝 Text: " . $BaseText . "\n🏷️ Event: " . $EventName;
        }

        return "❌ Speak-Test fehlgeschlagen!\n\nBitte prüfe:\n• Default-Charakter zugewiesen?\n• Charakter korrekt konfiguriert?\n\n→ Details im Debug-Log beider Instanzen.";
    }
}

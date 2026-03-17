<?php

declare(strict_types=1);

class VoiceAssistantGateway extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // API Keys
        $this->RegisterPropertyString('OpenAIKey', '');
        $this->RegisterPropertyString('ElevenLabsKey', '');
        $this->RegisterPropertyInteger('DefaultCharacter_ID', 0);
        
        // LLM
        $this->RegisterPropertyString('LLM_Base_URL', 'https://api.openai.com/v1/chat/completions');
        $this->RegisterPropertyString('LLM_Model', 'gpt-4o-mini');

        // FTP / Webserver
        $this->RegisterPropertyString('FTP_Host', '');
        $this->RegisterPropertyString('FTP_User', '');
        $this->RegisterPropertyString('FTP_Password', '');
        $this->RegisterPropertyString('FTP_RemotePath', '/voice/');
        $this->RegisterPropertyString('Webserver_BaseURL', '');

        // Echo / Alexa
        $this->RegisterPropertyInteger('EchoRemote_ID', 0);

        // Status Variables
        $this->RegisterVariableString('LastSpokenText', 'Last Spoken Text', '', 10);
        $this->RegisterVariableInteger('LastMediaID', 'Last Media ID', '', 20);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $openAIKey = $this->ReadPropertyString('OpenAIKey');
        $elevenLabsKey = $this->ReadPropertyString('ElevenLabsKey');
        $llmUrl = $this->ReadPropertyString('LLM_Base_URL');

        if (empty($openAIKey) || empty($elevenLabsKey) || empty($llmUrl)) {
            $this->SetStatus(200);
            $missing = [];
            if (empty($openAIKey)) $missing[] = 'OpenAI Key';
            if (empty($elevenLabsKey)) $missing[] = 'ElevenLabs Key';
            if (empty($llmUrl)) $missing[] = 'LLM Base URL';
            $this->SendDebug('ApplyChanges', '⚠️ Fehlende Konfiguration: ' . implode(', ', $missing), 0);
        } else {
            $this->SetStatus(102);
            $this->SendDebug('ApplyChanges', '✅ Gateway konfiguriert.', 0);
        }
    }

    // ═══════════════════════════════════════════
    //  DataFlow: ForwardData (Child -> Parent)
    // ═══════════════════════════════════════════

    public function ForwardData($JSONString)
    {
        $this->SendDebug('┌─ ForwardData', '═══════════════════════════════════════', 0);
        $this->SendDebug('│ ForwardData', 'Empfangen (' . strlen($JSONString) . ' Bytes)', 0);
        $this->SendDebug('│ ForwardData', $JSONString, 0);

        $data = json_decode($JSONString, true);
        
        if ($data === null) {
            $this->SendDebug('│ ForwardData ❌', 'JSON Parsing fehlgeschlagen: ' . json_last_error_msg(), 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        if (!isset($data['DataID'])) {
            $this->SendDebug('│ ForwardData ❌', 'Kein DataID-Feld. Keys: ' . implode(', ', array_keys($data)), 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        $this->SendDebug('│ ForwardData', 'DataID: ' . $data['DataID'], 0);

        if ($data['DataID'] !== '{E6892CCF-7622-4217-9150-C1DE886296DD}') {
            $this->SendDebug('│ ForwardData ❌', 'DataID-Mismatch! Erwartet: {E6892CCF-...}', 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        if (!isset($data['Function'])) {
            $this->SendDebug('│ ForwardData ❌', 'Kein Function-Feld.', 0);
            $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
            return '';
        }

        $function = $data['Function'];
        $buffer = isset($data['Buffer']) ? $data['Buffer'] : [];
        $this->SendDebug('│ ForwardData', '→ Function: ' . $function, 0);

        $result = '';
        switch ($function) {
            case 'ForwardToLLM':
                $this->SendDebug('│ ForwardData', '▶ ForwardToLLM...', 0);
                $result = $this->ForwardToLLM($buffer['SystemPrompt'], $buffer['BaseText'], $buffer['EventName']);
                $this->SendDebug('│ ForwardData', '◀ LLM: ' . strlen($result) . ' Zeichen', 0);
                break;

            case 'ForwardToElevenLabs':
                $this->SendDebug('│ ForwardData', '▶ ForwardToElevenLabs...', 0);
                $rawAudio = $this->ForwardToElevenLabs($buffer['Text'], $buffer['VoiceID'], $buffer['ModelID']);
                $this->SendDebug('│ ForwardData', '◀ TTS Roh-Audio: ' . strlen($rawAudio) . ' Bytes', 0);
                if (!empty($rawAudio)) {
                    $result = base64_encode($rawAudio);
                    $this->SendDebug('│ ForwardData', '◀ Base64: ' . strlen($result) . ' Zeichen', 0);
                }
                break;

            case 'UploadToWebserver':
                $this->SendDebug('│ ForwardData', '▶ UploadToWebserver...', 0);
                $result = $this->UploadToWebserver($buffer['FilePath'], $buffer['FileName']);
                $this->SendDebug('│ ForwardData', '◀ URL: ' . $result, 0);
                break;

            case 'AnnounceOnEcho':
                $this->SendDebug('│ ForwardData', '▶ AnnounceOnEcho...', 0);
                $result = $this->AnnounceOnEcho($buffer['AudioURL'], $buffer['EchoDevices']);
                $this->SendDebug('│ ForwardData', '◀ Echo: ' . $result, 0);
                break;

            default:
                $this->SendDebug('│ ForwardData ❌', 'Unbekannte Funktion: ' . $function, 0);
                break;
        }

        $this->SendDebug('│ ForwardData', empty($result) ? '❌ LEER' : '✅ OK', 0);
        $this->SendDebug('└─ ForwardData', '═══════════════════════════════════════', 0);
        return $result;
    }

    // ═══════════════════════════════════════════
    //  Speak (Gateway-Level, über Default-Charakter)
    // ═══════════════════════════════════════════

    public function Speak(string $EventName, string $BaseText): int
    {
        $this->SendDebug('Speak', "Event: $EventName | Text: $BaseText", 0);
        $defaultCharacterId = $this->ReadPropertyInteger('DefaultCharacter_ID');

        if ($defaultCharacterId == 0 || !IPS_InstanceExists($defaultCharacterId)) {
            $this->SendDebug('Speak ❌', 'Kein gültiger Default-Charakter. ID: ' . $defaultCharacterId, 0);
            IPS_LogMessage('VoiceAssistantGateway', 'Speak: Kein gültiger Default-Charakter (ID: ' . $defaultCharacterId . ').');
            return 0;
        }

        $mediaId = IVD_Speak($defaultCharacterId, $EventName, $BaseText);
        $this->SendDebug('Speak', 'Ergebnis MediaID: ' . $mediaId, 0);

        if ($mediaId > 0) {
            $this->SetValue('LastSpokenText', $BaseText);
            $this->SetValue('LastMediaID', $mediaId);
        }

        return $mediaId;
    }

    // ═══════════════════════════════════════════
    //  ForwardToLLM
    // ═══════════════════════════════════════════

    public function ForwardToLLM(string $SystemPrompt, string $BaseText, string $EventName): string
    {
        $this->SendDebug('┌─ ForwardToLLM', '═══════════════════════════════════════', 0);
        $apiKey = $this->ReadPropertyString('OpenAIKey');
        $url = $this->ReadPropertyString('LLM_Base_URL');
        $model = $this->ReadPropertyString('LLM_Model');
        
        $this->SendDebug('│ ForwardToLLM', "URL: $url | Modell: $model", 0);
        $this->SendDebug('│ ForwardToLLM', 'API Key: ' . (!empty($apiKey) ? '✅ (' . strlen($apiKey) . ' Zeichen)' : '❌ FEHLT'), 0);
        $this->SendDebug('│ ForwardToLLM', 'Prompt: ' . substr($SystemPrompt, 0, 80), 0);

        if (empty($url)) {
            $this->SendDebug('│ ForwardToLLM ❌', 'URL leer!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'ForwardToLLM: URL leer.');
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

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $startTime = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $elapsed = round((microtime(true) - $startTime) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->SendDebug('│ ForwardToLLM', "HTTP $httpCode | {$elapsed}ms", 0);

        if ($response === false) {
            $this->SendDebug('│ ForwardToLLM ❌', "cURL #$errno: $error", 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToLLM: cURL #$errno: $error");
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        if ($httpCode >= 400) {
            $this->SendDebug('│ ForwardToLLM ❌', 'Response: ' . substr($response, 0, 500), 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToLLM: HTTP $httpCode - " . substr($response, 0, 300));
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        $responseData = json_decode($response, true);
        if ($responseData === null) {
            $this->SendDebug('│ ForwardToLLM ❌', 'JSON Parse Error: ' . json_last_error_msg(), 0);
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return '';
        }

        if (isset($responseData['choices'][0]['message']['content'])) {
            $result = trim($responseData['choices'][0]['message']['content']);
            $this->SendDebug('│ ForwardToLLM ✅', $result, 0);
            $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
            return $result;
        }

        $this->SendDebug('│ ForwardToLLM ❌', 'Keine choices in Antwort. Keys: ' . implode(', ', array_keys($responseData)), 0);
        if (isset($responseData['error'])) {
            $this->SendDebug('│ ForwardToLLM ❌', 'API Error: ' . json_encode($responseData['error']), 0);
        }
        $this->SendDebug('└─ ForwardToLLM', '═══════════════════════════════════════', 0);
        return '';
    }

    // ═══════════════════════════════════════════
    //  ForwardToElevenLabs (mit Alexa-kompatiblem Format)
    // ═══════════════════════════════════════════

    public function ForwardToElevenLabs(string $Text, string $VoiceID, string $ModelID): string
    {
        $this->SendDebug('┌─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
        $apiKey = $this->ReadPropertyString('ElevenLabsKey');
        
        $this->SendDebug('│ ForwardToElevenLabs', 'API Key: ' . (!empty($apiKey) ? '✅' : '❌ FEHLT'), 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'VoiceID: ' . (empty($VoiceID) ? '❌ LEER' : $VoiceID), 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'Text: ' . substr($Text, 0, 100), 0);

        if (empty($apiKey)) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', 'API Key fehlt!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'ForwardToElevenLabs: API Key fehlt.');
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        if (empty($VoiceID)) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', 'Voice ID fehlt!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'ForwardToElevenLabs: Voice ID fehlt.');
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        if (empty($ModelID)) {
            $ModelID = 'eleven_multilingual_v2';
        }

        // WICHTIG: output_format=mp3_22050_32 für Alexa SSML-Kompatibilität
        // (MPEG-2, 22050 Hz, 32 kbps – erfüllt Alexa-Anforderungen)
        $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $VoiceID . '?output_format=mp3_22050_32';
        $data = [
            'text' => $Text,
            'model_id' => $ModelID,
        ];

        $requestBody = json_encode($data);
        $this->SendDebug('│ ForwardToElevenLabs', 'URL: ' . $url, 0);
        $this->SendDebug('│ ForwardToElevenLabs', 'Format: mp3_22050_32 (Alexa SSML-kompatibel)', 0);

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

        $this->SendDebug('│ ForwardToElevenLabs', "HTTP $httpCode | {$elapsed}ms | $contentType", 0);

        if ($response === false) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', "cURL #$errno: $error", 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToElevenLabs: cURL #$errno: $error");
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        if ($httpCode >= 400) {
            $this->SendDebug('│ ForwardToElevenLabs ❌', 'Response: ' . substr($response, 0, 500), 0);
            IPS_LogMessage('VoiceAssistantGateway', "ForwardToElevenLabs: HTTP $httpCode - " . substr($response, 0, 300));
            $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
            return '';
        }

        $this->SendDebug('│ ForwardToElevenLabs ✅', 'Audio: ' . strlen($response) . ' Bytes', 0);
        $this->SendDebug('└─ ForwardToElevenLabs', '═══════════════════════════════════════', 0);
        return $response;
    }

    // ═══════════════════════════════════════════
    //  FTP Upload zum Webserver
    // ═══════════════════════════════════════════

    public function UploadToWebserver(string $localFilePath, string $remoteFileName): string
    {
        $this->SendDebug('┌─ UploadToWebserver', '═══════════════════════════════════════', 0);
        
        $host = $this->ReadPropertyString('FTP_Host');
        $user = $this->ReadPropertyString('FTP_User');
        $pass = $this->ReadPropertyString('FTP_Password');
        $remotePath = $this->ReadPropertyString('FTP_RemotePath');
        $baseURL = $this->ReadPropertyString('Webserver_BaseURL');

        $this->SendDebug('│ UploadToWebserver', "Host: $host | User: $user | RemotePath: $remotePath", 0);
        $this->SendDebug('│ UploadToWebserver', "Lokale Datei: $localFilePath", 0);
        $this->SendDebug('│ UploadToWebserver', "Remote Dateiname: $remoteFileName", 0);

        if (empty($host) || empty($user) || empty($pass)) {
            $this->SendDebug('│ UploadToWebserver ❌', 'FTP-Konfiguration unvollständig!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'UploadToWebserver: FTP-Konfiguration unvollständig.');
            $this->SendDebug('└─ UploadToWebserver', '═══════════════════════════════════════', 0);
            return '';
        }

        if (!file_exists($localFilePath)) {
            $this->SendDebug('│ UploadToWebserver ❌', 'Lokale Datei existiert nicht: ' . $localFilePath, 0);
            $this->SendDebug('└─ UploadToWebserver', '═══════════════════════════════════════', 0);
            return '';
        }

        // FTP-Verbindung
        $this->SendDebug('│ UploadToWebserver', 'Verbinde mit FTP...', 0);
        $ftp = @ftp_connect($host, 21, 10);
        if ($ftp === false) {
            $this->SendDebug('│ UploadToWebserver ❌', 'FTP-Verbindung fehlgeschlagen!', 0);
            IPS_LogMessage('VoiceAssistantGateway', "UploadToWebserver: FTP Connect zu $host fehlgeschlagen.");
            $this->SendDebug('└─ UploadToWebserver', '═══════════════════════════════════════', 0);
            return '';
        }

        // Login
        if (!@ftp_login($ftp, $user, $pass)) {
            $this->SendDebug('│ UploadToWebserver ❌', 'FTP-Login fehlgeschlagen!', 0);
            IPS_LogMessage('VoiceAssistantGateway', "UploadToWebserver: FTP Login als $user fehlgeschlagen.");
            ftp_close($ftp);
            $this->SendDebug('└─ UploadToWebserver', '═══════════════════════════════════════', 0);
            return '';
        }

        $this->SendDebug('│ UploadToWebserver', '✅ FTP Login OK', 0);

        // Passiv-Modus (meistens nötig hinter NAT/Firewall)
        ftp_pasv($ftp, true);

        // Remote-Pfad wechseln
        if (!empty($remotePath) && $remotePath !== '/') {
            if (!@ftp_chdir($ftp, $remotePath)) {
                $this->SendDebug('│ UploadToWebserver', 'Remote-Pfad existiert nicht, erstelle...', 0);
                @ftp_mkdir($ftp, $remotePath);
                @ftp_chdir($ftp, $remotePath);
            }
        }

        // Upload
        $this->SendDebug('│ UploadToWebserver', 'Lade hoch: ' . $remoteFileName . '...', 0);
        $success = @ftp_put($ftp, $remoteFileName, $localFilePath, FTP_BINARY);
        ftp_close($ftp);

        if (!$success) {
            $this->SendDebug('│ UploadToWebserver ❌', 'FTP Upload fehlgeschlagen!', 0);
            IPS_LogMessage('VoiceAssistantGateway', "UploadToWebserver: Upload von $remoteFileName fehlgeschlagen.");
            $this->SendDebug('└─ UploadToWebserver', '═══════════════════════════════════════', 0);
            return '';
        }

        // Öffentliche URL zusammenbauen
        $publicURL = rtrim($baseURL, '/') . '/' . $remoteFileName;
        $this->SendDebug('│ UploadToWebserver ✅', 'URL: ' . $publicURL, 0);
        $this->SendDebug('└─ UploadToWebserver', '═══════════════════════════════════════', 0);
        return $publicURL;
    }

    // ═══════════════════════════════════════════
    //  Echo / Alexa Announce
    // ═══════════════════════════════════════════

    public function AnnounceOnEcho(string $audioURL, string $echoDevicesJSON): string
    {
        $this->SendDebug('┌─ AnnounceOnEcho', '═══════════════════════════════════════', 0);
        
        $echoRemoteId = $this->ReadPropertyInteger('EchoRemote_ID');
        $this->SendDebug('│ AnnounceOnEcho', 'EchoRemote ID: ' . $echoRemoteId, 0);
        $this->SendDebug('│ AnnounceOnEcho', 'Audio URL: ' . $audioURL, 0);
        $this->SendDebug('│ AnnounceOnEcho', 'Echo Devices JSON: ' . $echoDevicesJSON, 0);

        if ($echoRemoteId == 0 || !IPS_InstanceExists($echoRemoteId)) {
            $this->SendDebug('│ AnnounceOnEcho ❌', 'Keine gültige EchoRemote-Instanz!', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'AnnounceOnEcho: Keine gültige EchoRemote-Instanz (ID: ' . $echoRemoteId . ').');
            $this->SendDebug('└─ AnnounceOnEcho', '═══════════════════════════════════════', 0);
            return 'FAIL';
        }

        $echos = json_decode($echoDevicesJSON, true);
        if (!is_array($echos) || count($echos) === 0) {
            $this->SendDebug('│ AnnounceOnEcho ❌', 'Keine Echo-Geräte im Array!', 0);
            $this->SendDebug('└─ AnnounceOnEcho', '═══════════════════════════════════════', 0);
            return 'FAIL';
        }

        $ssml = '<speak><audio src="' . $audioURL . '"/></speak>';
        $this->SendDebug('│ AnnounceOnEcho', 'SSML: ' . $ssml, 0);
        $this->SendDebug('│ AnnounceOnEcho', 'Sende an ' . count($echos) . ' Echo-Gerät(e)...', 0);

        ECHOREMOTE_TextToSpeechEx($echoRemoteId, $ssml, $echos, []);

        $this->SendDebug('│ AnnounceOnEcho ✅', 'SSML an Echos gesendet!', 0);
        $this->SendDebug('└─ AnnounceOnEcho', '═══════════════════════════════════════', 0);
        return 'OK';
    }

    // ═══════════════════════════════════════════
    //  Test-Funktionen
    // ═══════════════════════════════════════════

    public function TestLLM(string $SystemPrompt, string $BaseText, string $EventName): string
    {
        $this->SendDebug('TestLLM', '═══ MANUELLER TEST ═══', 0);
        $result = $this->ForwardToLLM($SystemPrompt, $BaseText, $EventName);
        if (empty($result)) {
            return "❌ LLM-Test fehlgeschlagen!\n\n→ Details im Debug-Log.";
        }
        return "✅ LLM-Test erfolgreich!\n\n📝 Text:\n" . $result;
    }

    public function TestTTS(string $Text, string $VoiceID, string $ModelID): string
    {
        $this->SendDebug('TestTTS', '═══ MANUELLER TEST ═══', 0);
        if (empty($VoiceID)) {
            return "❌ Voice ID leer!";
        }
        $result = $this->ForwardToElevenLabs($Text, $VoiceID, $ModelID);
        if (empty($result)) {
            return "❌ TTS-Test fehlgeschlagen!\n\n→ Details im Debug-Log.";
        }
        return "✅ TTS-Test erfolgreich!\n\n🔊 Audio: " . strlen($result) . " Bytes (Format: mp3_22050_32, Alexa-kompatibel)";
    }

    public function TestFTP(): string
    {
        $this->SendDebug('TestFTP', '═══ MANUELLER TEST ═══', 0);
        $host = $this->ReadPropertyString('FTP_Host');
        $user = $this->ReadPropertyString('FTP_User');
        $pass = $this->ReadPropertyString('FTP_Password');
        $remotePath = $this->ReadPropertyString('FTP_RemotePath');
        $baseURL = $this->ReadPropertyString('Webserver_BaseURL');

        $info = "🌐 Host: $host\n👤 User: $user\n📁 Pfad: $remotePath\n🔗 Base URL: $baseURL\n\n";

        if (empty($host) || empty($user) || empty($pass)) {
            return $info . "❌ FTP-Konfiguration unvollständig!";
        }

        $ftp = @ftp_connect($host, 21, 10);
        if ($ftp === false) {
            return $info . "❌ Verbindung zu $host fehlgeschlagen!";
        }

        if (!@ftp_login($ftp, $user, $pass)) {
            ftp_close($ftp);
            return $info . "❌ Login fehlgeschlagen!";
        }

        ftp_pasv($ftp, true);

        $dirOk = true;
        if (!empty($remotePath) && $remotePath !== '/') {
            $dirOk = @ftp_chdir($ftp, $remotePath);
        }

        $pwd = @ftp_pwd($ftp);
        ftp_close($ftp);

        $status = "✅ FTP-Verbindung erfolgreich!\n\n";
        $status .= "📁 Aktuelles Verzeichnis: $pwd\n";
        $status .= "📁 Zielverzeichnis: " . ($dirOk ? "✅ OK" : "⚠️ Nicht gefunden (wird beim Upload erstellt)");

        return $info . $status;
    }

    public function TestSpeak(string $EventName, string $BaseText): string
    {
        $this->SendDebug('TestSpeak', '═══ MANUELLER TEST ═══', 0);
        $mediaId = $this->Speak($EventName, $BaseText);
        if ($mediaId > 0) {
            return "✅ Speak-Test erfolgreich!\n\n🎵 Media-ID: $mediaId";
        }
        return "❌ Speak-Test fehlgeschlagen!\n\n→ Details im Debug-Log beider Instanzen.";
    }
}

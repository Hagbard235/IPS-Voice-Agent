<?php

declare(strict_types=1);

class VoiceCharacterDevice extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Name', 'New Character');
        $this->RegisterPropertyString('Voice_ID', '');
        $this->RegisterPropertyString('Model_ID', 'eleven_multilingual_v2');
        $this->RegisterPropertyString('LLM_SystemPrompt', 'Du bist ein freundlicher Assistent.');
        $this->RegisterPropertyInteger('Character_Image', 0);
        $this->RegisterPropertyInteger('Max_Variations', 3);

        $this->RegisterVariableString('LastSpokenText', 'Last Spoken Text', '', 10);
        $this->RegisterVariableInteger('LastMediaID', 'Last Media ID', '', 20);
        $this->RegisterVariableString('LastAudioURL', 'Last Audio URL', '', 30);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->CreateMediaDirectory();

        $voiceId = $this->ReadPropertyString('Voice_ID');
        $name = $this->ReadPropertyString('Name');

        if (!$this->HasActiveParent()) {
            $this->SetStatus(201);
            $this->SendDebug('ApplyChanges', '❌ Kein Gateway!', 0);
        } elseif (empty($voiceId)) {
            $this->SetStatus(200);
            $this->SendDebug('ApplyChanges', '⚠️ Voice ID leer!', 0);
        } else {
            $this->SetStatus(102);
            $this->SendDebug('ApplyChanges', "✅ '$name' aktiv.", 0);
        }
    }

    private function CreateMediaDirectory(): void
    {
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->SendDebug('CreateMediaDirectory', '📁 Erstellt: ' . $dir, 0);
        }
    }

    // ═══════════════════════════════════════════
    //  Speak (Hauptprozess)
    // ═══════════════════════════════════════════

    public function Speak(string $EventName, string $BaseText): int
    {
        $this->SendDebug('┌─ Speak', '═══════════════════════════════════════', 0);
        $this->SendDebug('│ Speak', "Event: $EventName | Text: $BaseText", 0);

        $maxVariations = $this->ReadPropertyInteger('Max_Variations');
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID . DIRECTORY_SEPARATOR;

        $searchPattern = $dir . $EventName . '_*.mp3';
        $existingFiles = glob($searchPattern);
        $fileCount = $existingFiles !== false ? count($existingFiles) : 0;
        $this->SendDebug('│ Speak', "Cache: $fileCount / $maxVariations Variationen", 0);

        // 1. Cache Check
        if ($fileCount >= $maxVariations && $fileCount > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $this->SendDebug('│ Speak', '🎯 Cache-Hit: ' . basename($randomFile), 0);
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->UpdateStatusVariables($BaseText, $mediaId);
                $this->SendDebug('│ Speak ✅', 'Cache MediaID: ' . $mediaId, 0);
                $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
                return $mediaId;
            }
        }

        // 2. Gateway Check
        if (!$this->HasActiveParent()) {
            $this->SendDebug('│ Speak ❌', 'Kein Gateway!', 0);
            IPS_LogMessage('VoiceCharacterDevice', 'Kein aktives Gateway.');
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        // 3. LLM via DataFlow
        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        $this->SendDebug('│ Speak', '📤 LLM-Request...', 0);
        
        try {
            $enhancedText = $this->SendDataToParent(json_encode([
                'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}',
                'Function' => 'ForwardToLLM',
                'Buffer' => [
                    'SystemPrompt' => $systemPrompt,
                    'BaseText' => $BaseText,
                    'EventName' => $EventName
                ]
            ]));
        } catch (\Exception $e) {
            $this->SendDebug('│ Speak ❌', 'LLM Exception: ' . $e->getMessage(), 0);
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        $this->SendDebug('│ Speak', '📥 LLM: ' . (empty($enhancedText) ? '❌ LEER' : '✅ ' . substr($enhancedText, 0, 150)), 0);

        if (empty($enhancedText)) {
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        // 4. TTS via DataFlow
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        $this->SendDebug('│ Speak', "📤 TTS-Request... VoiceID: $voiceId", 0);
        
        try {
            $audioBase64 = $this->SendDataToParent(json_encode([
                'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}',
                'Function' => 'ForwardToElevenLabs',
                'Buffer' => [
                    'Text' => $enhancedText,
                    'VoiceID' => $voiceId,
                    'ModelID' => $modelId
                ]
            ]));
        } catch (\Exception $e) {
            $this->SendDebug('│ Speak ❌', 'TTS Exception: ' . $e->getMessage(), 0);
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        if (empty($audioBase64)) {
            $this->SendDebug('│ Speak ❌', 'TTS: leere Antwort', 0);
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        // 5. Base64 → Binary
        $audioData = base64_decode($audioBase64, true);
        if ($audioData === false) {
            $this->SendDebug('│ Speak ❌', 'Base64-Dekodierung fehlgeschlagen!', 0);
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }
        $this->SendDebug('│ Speak', '✅ Audio dekodiert: ' . strlen($audioData) . ' Bytes', 0);

        // 6. Speichern
        $newFilename = $EventName . '_' . ($fileCount + 1) . '.mp3';
        $fullFilePath = $dir . $newFilename;
        file_put_contents($fullFilePath, $audioData);
        $this->SendDebug('│ Speak', '💾 Gespeichert: ' . $fullFilePath . ' (' . filesize($fullFilePath) . ' Bytes)', 0);

        // 7. IPS Media-Objekt
        $mediaId = $this->GetMediaIdByFilename($newFilename);
        if ($mediaId === 0) {
            $mediaId = IPS_CreateMedia(1);
            IPS_SetParent($mediaId, $this->InstanceID);
            IPS_SetName($mediaId, $EventName . ' - Variation ' . ($fileCount + 1));
            $this->SendDebug('│ Speak', '📎 Media erstellt: ID ' . $mediaId, 0);
        }
        
        $relativeMediaDir = 'media/voice_' . $this->InstanceID . '/';
        IPS_SetMediaFile($mediaId, $relativeMediaDir . $newFilename, false);

        // 8. FTP Upload → öffentliche URL
        $this->SendDebug('│ Speak', '📤 FTP Upload...', 0);
        try {
            $publicURL = $this->SendDataToParent(json_encode([
                'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}',
                'Function' => 'UploadToWebserver',
                'Buffer' => [
                    'FilePath' => $fullFilePath,
                    'FileName' => $newFilename
                ]
            ]));
        } catch (\Exception $e) {
            $this->SendDebug('│ Speak ⚠️', 'FTP Upload Exception: ' . $e->getMessage(), 0);
            $publicURL = '';
        }

        if (!empty($publicURL)) {
            $this->SendDebug('│ Speak ✅', 'Public URL: ' . $publicURL, 0);
            $this->SetValue('LastAudioURL', $publicURL);
        } else {
            $this->SendDebug('│ Speak ⚠️', 'FTP Upload fehlgeschlagen (MP3 trotzdem lokal gespeichert)', 0);
        }

        $this->UpdateStatusVariables($BaseText, $mediaId);
        $this->SendDebug('│ Speak ✅', "MediaID: $mediaId", 0);
        $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
        return $mediaId;
    }

    // ═══════════════════════════════════════════
    //  SpeakAndAnnounce (Speak + Upload + Echo)
    // ═══════════════════════════════════════════

    /**
     * Vollständiger Durchlauf: Speak + FTP Upload + Echo Announce
     * @param string $EventName Event-Name
     * @param string $BaseText Basis-Text
     * @param string $EchoDevicesJSON JSON-Array der Echo-Geräte-Seriennummern
     */
    public function SpeakAndAnnounce(string $EventName, string $BaseText, string $EchoDevicesJSON): int
    {
        $this->SendDebug('SpeakAndAnnounce', "Event: $EventName | Echos: $EchoDevicesJSON", 0);

        // 1. Speak (generiert MP3 + Upload)
        $mediaId = $this->Speak($EventName, $BaseText);

        if ($mediaId === 0) {
            $this->SendDebug('SpeakAndAnnounce ❌', 'Speak fehlgeschlagen, kein Announce.', 0);
            return 0;
        }

        // 2. Echo Announce
        $publicURL = $this->GetValue('LastAudioURL');
        if (empty($publicURL)) {
            $this->SendDebug('SpeakAndAnnounce ⚠️', 'Keine Public URL vorhanden, Echo kann MP3 nicht abrufen.', 0);
            return $mediaId;
        }

        if (empty($EchoDevicesJSON) || $EchoDevicesJSON === '[]') {
            $this->SendDebug('SpeakAndAnnounce', 'Kein Echo-Array übergeben, überspringe Announce.', 0);
            return $mediaId;
        }

        $this->SendDebug('SpeakAndAnnounce', '📢 Sende an Echo...', 0);
        try {
            $this->SendDataToParent(json_encode([
                'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}',
                'Function' => 'AnnounceOnEcho',
                'Buffer' => [
                    'AudioURL' => $publicURL,
                    'EchoDevices' => $EchoDevicesJSON
                ]
            ]));
            $this->SendDebug('SpeakAndAnnounce ✅', 'Echo Announce gesendet!', 0);
        } catch (\Exception $e) {
            $this->SendDebug('SpeakAndAnnounce ❌', 'Echo Exception: ' . $e->getMessage(), 0);
        }

        return $mediaId;
    }

    // ═══════════════════════════════════════════
    //  Hilfsfunktionen
    // ═══════════════════════════════════════════

    private function GetMediaIdByFilename(string $filename): int
    {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childId) {
            if (IPS_MediaExists($childId)) {
                $mediaFile = IPS_GetMedia($childId)['MediaFile'];
                if (basename($mediaFile) === $filename) {
                    return $childId;
                }
            }
        }
        return 0;
    }

    private function FallbackToCache(array $existingFiles, string $baseText): int
    {
        $this->SendDebug('FallbackToCache', 'Dateien: ' . count($existingFiles), 0);
        if (count($existingFiles) > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->SendDebug('FallbackToCache ✅', 'Verwende: ' . basename($randomFile), 0);
                IPS_LogMessage('VoiceCharacterDevice', 'Fallback: ' . basename($randomFile));
                $this->UpdateStatusVariables($baseText, $mediaId);
                return $mediaId;
            }
        }
        $this->SendDebug('FallbackToCache ❌', 'Kein Cache!', 0);
        IPS_LogMessage('VoiceCharacterDevice', 'Kein Cache vorhanden.');
        return 0;
    }

    private function UpdateStatusVariables(string $text, int $mediaId): void
    {
        $this->SetValue('LastSpokenText', $text);
        $this->SetValue('LastMediaID', $mediaId);
    }

    // ═══════════════════════════════════════════
    //  Test-Funktionen
    // ═══════════════════════════════════════════

    public function TestSpeak(string $EventName, string $BaseText): string
    {
        $this->SendDebug('TestSpeak', '═══ TEST ═══', 0);
        $mediaId = $this->Speak($EventName, $BaseText);
        if ($mediaId > 0) {
            $url = $this->GetValue('LastAudioURL');
            $info = "✅ Speak erfolgreich!\n\n🎵 Media-ID: $mediaId";
            if (!empty($url)) {
                $info .= "\n🔗 URL: $url";
            }
            return $info;
        }
        return "❌ Speak fehlgeschlagen!\n\n→ Debug-Log prüfen.";
    }

    public function TestSpeakAndAnnounce(string $EventName, string $BaseText, string $EchoDevicesJSON): string
    {
        $this->SendDebug('TestSpeakAndAnnounce', '═══ TEST ═══', 0);
        
        if (empty($EchoDevicesJSON)) {
            return "❌ Bitte Echo Devices als JSON-Array angeben!\n\nBeispiel: [\"SERIAL1\",\"SERIAL2\"]";
        }

        $mediaId = $this->SpeakAndAnnounce($EventName, $BaseText, $EchoDevicesJSON);
        if ($mediaId > 0) {
            $url = $this->GetValue('LastAudioURL');
            $info = "✅ Speak + Announce erfolgreich!\n\n🎵 Media-ID: $mediaId";
            if (!empty($url)) {
                $info .= "\n🔗 URL: $url";
            }
            $info .= "\n📢 Echo Announce gesendet!";
            return $info;
        }
        return "❌ Fehlgeschlagen!\n\n→ Debug-Log beider Instanzen prüfen.";
    }

    public function TestDataFlow(string $TestText): string
    {
        $this->SendDebug('TestDataFlow', '═══ TEST ═══', 0);
        
        if (!$this->HasActiveParent()) {
            return "❌ Kein Gateway verbunden!";
        }

        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        try {
            $result = $this->SendDataToParent(json_encode([
                'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}',
                'Function' => 'ForwardToLLM',
                'Buffer' => [
                    'SystemPrompt' => $systemPrompt,
                    'BaseText' => $TestText,
                    'EventName' => 'dataflow_test'
                ]
            ]));
        } catch (\Exception $e) {
            return "❌ Exception: " . $e->getMessage();
        }

        if (empty($result)) {
            return "❌ Leere Antwort vom Gateway.\n\n→ Debug-Log des Gateways prüfen!";
        }

        return "✅ DataFlow OK!\n\n📥 LLM-Antwort:\n$result";
    }

    public function GetDiagnostics(): string
    {
        $name = $this->ReadPropertyString('Name');
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        $maxVar = $this->ReadPropertyInteger('Max_Variations');
        $hasParent = $this->HasActiveParent();

        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID;
        $files = is_dir($dir) ? glob($dir . DIRECTORY_SEPARATOR . '*.mp3') : [];
        $fileCount = $files !== false ? count($files) : 0;

        $s  = "═══ DIAGNOSE: $name ═══\n\n";
        $s .= "📋 ID: " . $this->InstanceID . "\n";
        $s .= "🔗 Gateway: " . ($hasParent ? '✅' : '❌') . "\n";
        if ($hasParent) {
            $s .= "   Gateway-ID: " . IPS_GetInstance($this->InstanceID)['ConnectionID'] . "\n";
        }
        $s .= "\n── Konfiguration ──\n";
        $s .= "🎤 Voice ID: " . (empty($voiceId) ? '❌' : '✅ ' . $voiceId) . "\n";
        $s .= "🤖 Modell: $modelId\n";
        $s .= "🔄 Max Variationen: $maxVar\n";
        $s .= "\n── Cache ──\n";
        $s .= "📁 Verzeichnis: " . (is_dir($dir) ? '✅' : '❌') . "\n";
        $s .= "🎵 MP3s: $fileCount\n";

        if ($fileCount > 0 && $files !== false) {
            foreach ($files as $f) {
                $s .= "   📄 " . basename($f) . " (" . round(filesize($f) / 1024) . " KB)\n";
            }
        }

        $s .= "\n── Status ──\n";
        $s .= "📝 Letzter Text: " . $this->GetValue('LastSpokenText') . "\n";
        $s .= "🎵 Letzte Media-ID: " . $this->GetValue('LastMediaID') . "\n";
        $s .= "🔗 Letzte URL: " . $this->GetValue('LastAudioURL') . "\n";

        return $s;
    }
}

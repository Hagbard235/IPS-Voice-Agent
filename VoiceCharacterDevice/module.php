<?php

declare(strict_types=1);

class VoiceCharacterDevice extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Name', 'New Character');
        $this->RegisterPropertyString('Voice_ID', '');
        $this->RegisterPropertyString('Model_ID', 'eleven_multilingual_v2');
        $this->RegisterPropertyString('LLM_SystemPrompt', 'Du bist ein freundlicher Assistent.');
        $this->RegisterPropertyInteger('Character_Image', 0);
        $this->RegisterPropertyInteger('Max_Variations', 3);

        // Status Variables specific to the character
        $this->RegisterVariableString('LastSpokenText', 'Last Spoken Text', '', 10);
        $this->RegisterVariableInteger('LastMediaID', 'Last Media ID', '', 20);
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->CreateMediaDirectory();

        // Konfiguration validieren
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $name = $this->ReadPropertyString('Name');

        if (!$this->HasActiveParent()) {
            $this->SetStatus(201); // Kein Gateway
            $this->SendDebug('ApplyChanges', '❌ Kein aktives Gateway verbunden!', 0);
        } elseif (empty($voiceId)) {
            $this->SetStatus(200); // Konfiguration unvollständig
            $this->SendDebug('ApplyChanges', '⚠️ Voice ID ist leer!', 0);
        } else {
            $this->SetStatus(102); // Aktiv
            $this->SendDebug('ApplyChanges', "✅ Charakter '$name' aktiv. Voice ID: $voiceId", 0);
        }

        // Verbindungsinfo loggen
        if ($this->HasActiveParent()) {
            $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            $this->SendDebug('ApplyChanges', '🔗 Verbunden mit Gateway-ID: ' . $parentID, 0);
        }
    }

    private function CreateMediaDirectory(): void
    {
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->SendDebug('CreateMediaDirectory', '📁 Verzeichnis erstellt: ' . $dir, 0);
        }
    }

    // ─────────────────────────────────────────
    //  Speak (Hauptprozess)
    // ─────────────────────────────────────────

    public function Speak(string $EventName, string $BaseText): int
    {
        $this->SendDebug('┌─ Speak', '═══════════════════════════════════════', 0);
        $this->SendDebug('│ Speak', 'Event: ' . $EventName, 0);
        $this->SendDebug('│ Speak', 'Text: ' . $BaseText, 0);

        $maxVariations = $this->ReadPropertyInteger('Max_Variations');
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID . DIRECTORY_SEPARATOR;
        $this->SendDebug('│ Speak', 'Media-Verzeichnis: ' . $dir, 0);
        $this->SendDebug('│ Speak', 'Max Variationen: ' . $maxVariations, 0);

        $searchPattern = $dir . $EventName . '_*.mp3';
        $existingFiles = glob($searchPattern);
        $fileCount = $existingFiles !== false ? count($existingFiles) : 0;
        $this->SendDebug('│ Speak', "Gefundene Dateien für '$EventName': $fileCount (Pattern: $searchPattern)", 0);

        // 1. Cache Check
        if ($fileCount >= $maxVariations && $fileCount > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $this->SendDebug('│ Speak', '🎯 Cache-Hit! Verwende: ' . basename($randomFile), 0);
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->SendDebug('│ Speak ✅', 'Cache-MediaID: ' . $mediaId, 0);
                $this->UpdateStatusVariables($BaseText, $mediaId);
                $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
                return $mediaId;
            }
            $this->SendDebug('│ Speak ⚠️', 'Datei existiert, aber kein IPS-Media-Objekt gefunden. Generiere neu...', 0);
        }

        // 2. Parent / Gateway Validierung
        if (!$this->HasActiveParent()) {
            $this->SendDebug('│ Speak ❌', 'Kein aktives Gateway verbunden!', 0);
            IPS_LogMessage('VoiceCharacterDevice', 'Kein aktives Gateway gefunden.');
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $this->SendDebug('│ Speak', '🔗 Gateway-ID: ' . $parentID, 0);

        // 3. LLM API Call via DataFlow
        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        $this->SendDebug('│ Speak', '📤 Sende LLM-Request an Gateway...', 0);
        $this->SendDebug('│ Speak', '   SystemPrompt: ' . substr($systemPrompt, 0, 80) . (strlen($systemPrompt) > 80 ? '...' : ''), 0);
        
        $llmPayload = json_encode([
            'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}', // MUSS die implemented-GUID des Gateways sein!
            'Function' => 'ForwardToLLM',
            'Buffer' => [
                'SystemPrompt' => $systemPrompt,
                'BaseText' => $BaseText,
                'EventName' => $EventName
            ]
        ]);
        $this->SendDebug('│ Speak', 'LLM Payload: ' . $llmPayload, 0);

        try {
            $enhancedText = $this->SendDataToParent($llmPayload);
        } catch (\Exception $e) {
            $this->SendDebug('│ Speak ❌', 'SendDataToParent Exception: ' . $e->getMessage(), 0);
            IPS_LogMessage('VoiceCharacterDevice', 'SendDataToParent für LLM fehlgeschlagen: ' . $e->getMessage());
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        $this->SendDebug('│ Speak', '📥 LLM-Antwort: ' . (empty($enhancedText) ? '❌ LEER' : '✅ ' . substr($enhancedText, 0, 200)), 0);

        if (empty($enhancedText)) {
            $this->SendDebug('│ Speak ❌', 'LLM lieferte leeren Text. Fallback auf Cache...', 0);
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        // 4. ElevenLabs API Call via DataFlow
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        $this->SendDebug('│ Speak', '📤 Sende TTS-Request an Gateway...', 0);
        $this->SendDebug('│ Speak', "   VoiceID: $voiceId | ModelID: $modelId", 0);
        
        $ttsPayload = json_encode([
            'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}', // MUSS die implemented-GUID des Gateways sein!
            'Function' => 'ForwardToElevenLabs',
            'Buffer' => [
                'Text' => $enhancedText,
                'VoiceID' => $voiceId,
                'ModelID' => $modelId
            ]
        ]);
        $this->SendDebug('│ Speak', 'TTS Payload (Länge: ' . strlen($ttsPayload) . ')', 0);

        try {
            $audioStream = $this->SendDataToParent($ttsPayload);
        } catch (\Exception $e) {
            $this->SendDebug('│ Speak ❌', 'SendDataToParent Exception (TTS): ' . $e->getMessage(), 0);
            IPS_LogMessage('VoiceCharacterDevice', 'SendDataToParent für TTS fehlgeschlagen: ' . $e->getMessage());
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        $audioLen = strlen($audioStream);
        $this->SendDebug('│ Speak', '📥 TTS-Antwort: ' . ($audioLen == 0 ? '❌ LEER' : "✅ $audioLen Bytes Audio"), 0);

        if (empty($audioStream)) {
            $this->SendDebug('│ Speak ❌', 'TTS lieferte keinen Audio-Stream. Fallback auf Cache...', 0);
            $result = $this->FallbackToCache($existingFiles, $BaseText);
            $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
            return $result;
        }

        // 5. Speichern und Registrieren
        $newFilename = $EventName . '_' . ($fileCount + 1) . '.mp3';
        $fullFilePath = $dir . $newFilename;
        $this->SendDebug('│ Speak', '💾 Speichere Datei: ' . $fullFilePath, 0);
        file_put_contents($fullFilePath, $audioStream);
        $this->SendDebug('│ Speak', '✅ Datei gespeichert (' . filesize($fullFilePath) . ' Bytes)', 0);

        // Recycling: Existiert das Media Objekt schon?
        $mediaId = $this->GetMediaIdByFilename($newFilename);
        
        if ($mediaId === 0) {
            $mediaId = IPS_CreateMedia(1);
            IPS_SetParent($mediaId, $this->InstanceID);
            IPS_SetName($mediaId, $EventName . ' - Variation ' . ($fileCount + 1));
            $this->SendDebug('│ Speak', '📎 Neues Media-Objekt erstellt: ID ' . $mediaId, 0);
        } else {
            $this->SendDebug('│ Speak', '♻️ Bestehendes Media-Objekt wiederverwendet: ID ' . $mediaId, 0);
        }
        
        // ZWINGEND: Forward Slashes für die IPS Media-Registrierung
        $relativeMediaDir = 'media/voice_' . $this->InstanceID . '/';
        IPS_SetMediaFile($mediaId, $relativeMediaDir . $newFilename, false);
        $this->SendDebug('│ Speak', '📎 Media registriert: ' . $relativeMediaDir . $newFilename, 0);

        $this->UpdateStatusVariables($BaseText, $mediaId);
        $this->SendDebug('│ Speak ✅', "Erfolgreich! MediaID: $mediaId", 0);
        $this->SendDebug('└─ Speak', '═══════════════════════════════════════', 0);
        return $mediaId;
    }

    // ─────────────────────────────────────────
    //  Hilfsfunktionen
    // ─────────────────────────────────────────

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
        $this->SendDebug('FallbackToCache', 'Verfügbare Cache-Dateien: ' . count($existingFiles), 0);
        if (count($existingFiles) > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $this->SendDebug('FallbackToCache', '🔄 Verwende: ' . basename($randomFile), 0);
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->SendDebug('FallbackToCache ✅', 'Fallback-MediaID: ' . $mediaId, 0);
                IPS_LogMessage('VoiceCharacterDevice', 'API Fehler - Verwende gecachte Fallback-Datei: ' . basename($randomFile));
                $this->UpdateStatusVariables($baseText, $mediaId);
                return $mediaId;
            }
            $this->SendDebug('FallbackToCache ⚠️', 'Datei vorhanden, aber kein IPS-Media-Objekt gefunden.', 0);
        }
        
        $this->SendDebug('FallbackToCache ❌', 'Kein Cache vorhanden. Totaler Ausfall.', 0);
        IPS_LogMessage('VoiceCharacterDevice', 'Spracherzeugung fehlgeschlagen und kein Cache vorhanden.');
        return 0;
    }

    private function UpdateStatusVariables(string $text, int $mediaId): void
    {
        $this->SetValue('LastSpokenText', $text);
        $this->SetValue('LastMediaID', $mediaId);
    }

    // ─────────────────────────────────────────
    //  Test-Funktionen (für form.json Actions)
    // ─────────────────────────────────────────

    /**
     * Test: Vollständiger Speak-Durchlauf
     */
    public function TestSpeak(string $EventName, string $BaseText): string
    {
        $this->SendDebug('TestSpeak', '═══ MANUELLER SPEAK-TEST GESTARTET ═══', 0);
        
        $name = $this->ReadPropertyString('Name');
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');

        $info = "🎭 Charakter: $name\n🎤 Voice ID: " . (empty($voiceId) ? '❌ NICHT GESETZT' : $voiceId) . "\n🤖 Modell: $modelId\n🏷️ Event: $EventName\n📝 Text: $BaseText\n\n";

        if (empty($voiceId)) {
            return $info . "❌ Test abgebrochen: Bitte zuerst eine Voice ID konfigurieren.";
        }

        $mediaId = $this->Speak($EventName, $BaseText);

        if ($mediaId > 0) {
            return $info . "✅ Speak-Test erfolgreich!\n\n🎵 Media-ID: " . $mediaId;
        }

        return $info . "❌ Speak-Test fehlgeschlagen!\n\n→ Details im Debug-Log dieser Instanz und des Gateways.";
    }

    /**
     * Test: Nur den DataFlow zum Gateway testen (LLM-Aufruf)
     */
    public function TestDataFlow(string $TestText): string
    {
        $this->SendDebug('TestDataFlow', '═══ MANUELLER DATAFLOW-TEST GESTARTET ═══', 0);
        
        if (!$this->HasActiveParent()) {
            return "❌ Kein Gateway verbunden!\n\nBitte stelle sicher, dass ein Voice Assistant Gateway als übergeordnete Instanz konfiguriert ist.";
        }

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');

        $info = "🔗 Gateway-ID: $parentID\n📤 Test-Text: $TestText\n📝 System-Prompt: " . substr($systemPrompt, 0, 50) . "...\n\n";

        $this->SendDebug('TestDataFlow', 'Sende Test-Payload an Gateway...', 0);
        
        $payload = json_encode([
            'DataID' => '{E6892CCF-7622-4217-9150-C1DE886296DD}', // MUSS die implemented-GUID des Gateways sein!
            'Function' => 'ForwardToLLM',
            'Buffer' => [
                'SystemPrompt' => $systemPrompt,
                'BaseText' => $TestText,
                'EventName' => 'dataflow_test'
            ]
        ]);

        try {
            $result = $this->SendDataToParent($payload);
        } catch (\Exception $e) {
            return $info . "❌ SendDataToParent Exception!\n\n" . $e->getMessage() . "\n\n→ Mögliche Ursache: DataID-Mismatch oder Gateway implementiert ForwardData nicht.";
        }

        if (empty($result)) {
            return $info . "❌ DataFlow-Test fehlgeschlagen!\n\nDas Gateway hat einen leeren String zurückgegeben.\n\n→ Prüfe das Debug-Log des Gateways!";
        }

        return $info . "✅ DataFlow-Test erfolgreich!\n\n📥 LLM-Antwort:\n" . $result;
    }

    /**
     * Diagnose: Status und Konfiguration prüfen
     */
    public function GetDiagnostics(): string
    {
        $name = $this->ReadPropertyString('Name');
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        $maxVariations = $this->ReadPropertyInteger('Max_Variations');
        $hasParent = $this->HasActiveParent();

        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID;
        $dirExists = is_dir($dir);
        $fileCount = 0;
        if ($dirExists) {
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.mp3');
            $fileCount = $files !== false ? count($files) : 0;
        }

        $status  = "═══ DIAGNOSE: $name ═══\n\n";
        $status .= "📋 Instanz-ID: " . $this->InstanceID . "\n";
        $status .= "🔗 Gateway verbunden: " . ($hasParent ? '✅ Ja' : '❌ Nein') . "\n";
        
        if ($hasParent) {
            $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            $status .= "   Gateway-ID: " . $parentID . "\n";
        }

        $status .= "\n── Konfiguration ──\n";
        $status .= "🎤 Voice ID: " . (empty($voiceId) ? '❌ NICHT GESETZT' : '✅ ' . $voiceId) . "\n";
        $status .= "🤖 Modell: " . $modelId . "\n";
        $status .= "📝 System-Prompt: " . (empty($systemPrompt) ? '❌ LEER' : '✅ (' . strlen($systemPrompt) . ' Zeichen)') . "\n";
        $status .= "🔄 Max Variationen: " . $maxVariations . "\n";

        $status .= "\n── Dateisystem ──\n";
        $status .= "📁 Verzeichnis: " . ($dirExists ? '✅ ' . $dir : '❌ Nicht vorhanden') . "\n";
        $status .= "🎵 Gecachte MP3s: " . $fileCount . "\n";

        if ($fileCount > 0 && $files !== false) {
            $status .= "\n── Cache-Dateien ──\n";
            foreach ($files as $file) {
                $size = round(filesize($file) / 1024);
                $status .= "   📄 " . basename($file) . " ({$size} KB)\n";
            }
        }

        $status .= "\n── Statusvariablen ──\n";
        $status .= "📝 Letzter Text: " . $this->GetValue('LastSpokenText') . "\n";
        $status .= "🎵 Letzte Media-ID: " . $this->GetValue('LastMediaID') . "\n";

        return $status;
    }
}

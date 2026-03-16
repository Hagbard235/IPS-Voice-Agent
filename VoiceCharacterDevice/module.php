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
        $this->RegisterPropertyString('Model_ID', 'eleven_multilingual_v3');
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
    }

    private function CreateMediaDirectory(): void
    {
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * Public Speak function for the specific character
     */
    public function Speak(string $EventName, string $BaseText): int
    {
        $maxVariations = $this->ReadPropertyInteger('Max_Variations');
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID . DIRECTORY_SEPARATOR;

        $searchPattern = $dir . $EventName . '_*.mp3';
        $existingFiles = glob($searchPattern);
        $fileCount = $existingFiles !== false ? count($existingFiles) : 0;

        // 1. Cache Check
        if ($fileCount >= $maxVariations && $fileCount > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->UpdateStatusVariables($BaseText, $mediaId);
                return $mediaId;
            }
        }

        // 2. Parent / Gateway Validierung
        if (!$this->HasActiveParent()) {
            IPS_LogMessage('VoiceCharacterDevice', 'Kein aktives Gateway gefunden.');
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // 3. LLM API Call via DataFlow
        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        
        $enhancedText = $this->SendDataToParent(json_encode([
            'DataID' => '{597658C0-741E-47C2-AF94-734B0B7F839A}', // Device-Interface ID
            'Function' => 'ForwardToLLM',
            'Buffer' => [
                'SystemPrompt' => $systemPrompt,
                'BaseText' => $BaseText,
                'EventName' => $EventName
            ]
        ]));

        if (empty($enhancedText)) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // 4. ElevenLabs API Call via DataFlow
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        
        $audioStream = $this->SendDataToParent(json_encode([
            'DataID' => '{597658C0-741E-47C2-AF94-734B0B7F839A}', // Device-Interface ID
            'Function' => 'ForwardToElevenLabs',
            'Buffer' => [
                'Text' => $enhancedText,
                'VoiceID' => $voiceId,
                'ModelID' => $modelId
            ]
        ]));

        if (empty($audioStream)) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // 5. Speichern und Registrieren
        $newFilename = $EventName . '_' . ($fileCount + 1) . ".mp3";
        $fullFilePath = $dir . $newFilename;
        file_put_contents($fullFilePath, $audioStream);

        // Recycling: Existiert das Media Objekt schon?
        $mediaId = $this->GetMediaIdByFilename($newFilename);
        
        if ($mediaId === 0) {
            $mediaId = IPS_CreateMedia(1);
            IPS_SetParent($mediaId, $this->InstanceID);
            IPS_SetName($mediaId, $EventName . ' - Variation ' . ($fileCount + 1));
        }
        
        // ZWINGEND: Forward Slashes für die IPS Media-Registrierung
        $relativeMediaDir = 'media/voice_' . $this->InstanceID . '/';
        IPS_SetMediaFile($mediaId, $relativeMediaDir . $newFilename, false);

        $this->UpdateStatusVariables($BaseText, $mediaId);
        return $mediaId;
    }

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
        if (count($existingFiles) > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                IPS_LogMessage('VoiceCharacterDevice', 'API Fehler - Verwende gecachte Fallback-Datei.');
                $this->UpdateStatusVariables($baseText, $mediaId);
                return $mediaId;
            }
        }
        
        IPS_LogMessage('VoiceCharacterDevice', 'Spracherzeugung fehlgeschlagen und kein Cache vorhanden.');
        return 0;
    }

    private function UpdateStatusVariables(string $text, int $mediaId): void
    {
        $this->SetValue('LastSpokenText', $text);
        $this->SetValue('LastMediaID', $mediaId);
    }
}

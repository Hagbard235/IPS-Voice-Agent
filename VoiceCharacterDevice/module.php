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

        // Create the media directory for this instance if it does not exist yet
        $this->CreateMediaDirectory();
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->CreateMediaDirectory();
    }

    private function CreateMediaDirectory()
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

        if ($fileCount >= $maxVariations && $fileCount > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->UpdateStatusVariables($BaseText, $mediaId);
                return $mediaId;
            }
        }

        if (!$this->HasActiveParent()) {
            IPS_LogMessage('VoiceCharacterDevice', 'Kein übergeordnetes Gateway gefunden.');
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID == 0) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        // Call Parent Methods using prefix IVG
        $enhancedText = IVG_ForwardToLLM($parentID, $systemPrompt, $BaseText, $EventName);

        if (empty($enhancedText)) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        
        $audioStream = IVG_ForwardToElevenLabs($parentID, $enhancedText, $voiceId, $modelId);

        if (empty($audioStream)) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        $newFilename = $EventName . '_' . ($fileCount + 1) . '.mp3';
        $fullFilePath = $dir . $newFilename;
        file_put_contents($fullFilePath, $audioStream);

        $mediaId = IPS_CreateMedia(1);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetName($mediaId, $EventName . ' - Variation ' . ($fileCount + 1));
        
        $relativeMediaDir = 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID . DIRECTORY_SEPARATOR;
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

    private function UpdateStatusVariables(string $text, int $mediaId)
    {
        $this->SetValue('LastSpokenText', $text);
        $this->SetValue('LastMediaID', $mediaId);
    }
}

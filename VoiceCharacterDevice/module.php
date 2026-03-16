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

        $this->CreateMediaDirectory(); // Ensure it exists after any changes
    }

    private function CreateMediaDirectory()
    {
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * Clear Cache function (could be triggered from form.json or script)
     */
    public function ClearCache()
    {
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID . DIRECTORY_SEPARATOR;
        if (is_dir($dir)) {
            $files = glob($dir . '*.mp3');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            // Optional: delete corresponding IPS Media Objects?
            // Since they are children of this instance, we could loop over them:
            $mediaChildren = IPS_GetChildrenIDs($this->InstanceID);
            foreach ($mediaChildren as $childId) {
                if (IPS_MediaExists($childId)) {
                    IPS_DeleteMedia($childId, true); // true = delete file too
                }
            }
            IPS_LogMessage('VoiceCharacterDevice', 'Cache für Instanz ' . $this->InstanceID . ' wurde bereinigt.');
        }
    }

    /**
     * Public Speak function for the specific character
     */
    public function Speak(string $EventName, string $BaseText): int
    {
        $maxVariations = $this->ReadPropertyInteger('Max_Variations');
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'voice_' . $this->InstanceID . DIRECTORY_SEPARATOR;

        // Step 1: Cache Validation
        $searchPattern = $dir . $EventName . '_*.mp3';
        $existingFiles = glob($searchPattern);
        $fileCount = $existingFiles !== false ? count($existingFiles) : 0;

        // Condition A: Cache-Hit
        if ($fileCount >= $maxVariations && $fileCount > 0) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            $mediaId = $this->GetMediaIdByFilename(basename($randomFile));
            if ($mediaId > 0) {
                $this->UpdateStatusVariables($BaseText, $mediaId);
                return $mediaId;
            }
        }

        // Condition B: Cache-Miss
        if (!$this->HasActiveParent()) {
            IPS_LogMessage('VoiceCharacterDevice', 'Kein übergeordnetes Gateway gefunden.');
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // Action 1: LLM Call
        $systemPrompt = $this->ReadPropertyString('LLM_SystemPrompt');
        $enhancedText = $this->SendDataToParent(json_encode([
            'DataID' => '{some-guid-for-llm}', // Will need proper IPS DataFlow definition, or use IPS_RequestAction on parent
            'Action' => 'ForwardToLLM',
            'SystemPrompt' => $systemPrompt,
            'BaseText' => $BaseText,
            'EventName' => $EventName
        ]));

        // Alternative for Parent/Child Data Flow simpler approach (since IPSModule methods on parent aren't directly callable without RequestAction or raw DataFlow):
        // In this implementation plan we will use the standard parent method calling via InstanceInterface:
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID == 0) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // Call Parent Methods using reflection/direct Call if possible, or standard IPS_RequestAction if defined in form.
        // Actually, the cleanest way in IPS for Parent calls from Child without complex DataFlow is using the parent's module functions:
        // Assume Gateway module has prefix VGW:
        $enhancedText = VGW_ForwardToLLM($parentID, $systemPrompt, $BaseText, $EventName);

        if (empty($enhancedText)) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // Action 2: ElevenLabs Call
        $voiceId = $this->ReadPropertyString('Voice_ID');
        $modelId = $this->ReadPropertyString('Model_ID');
        
        $audioStream = VGW_ForwardToElevenLabs($parentID, $enhancedText, $voiceId, $modelId);

        if (empty($audioStream)) {
            return $this->FallbackToCache($existingFiles, $BaseText);
        }

        // Action 3: Save File
        $newFilename = $EventName . '_' . ($fileCount + 1) . '.mp3';
        $fullFilePath = $dir . $newFilename;
        file_put_contents($fullFilePath, $audioStream);

        // Action 4: IPS-Registration
        $mediaId = IPS_CreateMedia(1); // 1 = Audio
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetName($mediaId, $EventName . ' - Variation ' . ($fileCount + 1));
        
        // IPS uses relative paths for media files from the IPS root dir
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

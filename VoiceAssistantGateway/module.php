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
        
        // Neu: Konfigurierbares LLM
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
    }

    /**
     * DataFlow Entrance point (ReceiveData)
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        
        // Validate DataID (must match Voice-Device interface)
        if ($data['DataID'] !== '{597658C0-741E-47C2-AF94-734B0B7F839A}') {
            return '';
        }

        $function = $data['Function'];
        $buffer = $data['Buffer'];

        switch ($function) {
            case 'ForwardToLLM':
                return $this->ForwardToLLM($buffer['SystemPrompt'], $buffer['BaseText'], $buffer['EventName']);
            case 'ForwardToElevenLabs':
                return $this->ForwardToElevenLabs($buffer['Text'], $buffer['VoiceID'], $buffer['ModelID']);
            default:
                $this->SendDebug('ReceiveData', 'Unknown Function: ' . $function, 0);
                return '';
        }
    }

    /**
     * Public Speak function for the Gateway.
     * Routes the request to the DefaultCharacter_ID.
     */
    public function Speak(string $EventName, string $BaseText): int
    {
        $defaultCharacterId = $this->ReadPropertyInteger('DefaultCharacter_ID');

        if ($defaultCharacterId == 0 || !IPS_InstanceExists($defaultCharacterId)) {
            $this->SendDebug('Error', 'No valid default character specified.', 0);
            IPS_LogMessage('VoiceAssistantGateway', 'Die Speak-Funktion wurde aufgerufen, aber es ist kein gültiger Standard-Charakter hinterlegt.');
            return 0;
        }

        // Call the Speak function on the Child Device via prefix IVD
        $mediaId = IVD_Speak($defaultCharacterId, $EventName, $BaseText);

        if ($mediaId > 0) {
            $this->SetValue('LastSpokenText', $BaseText);
            $this->SetValue('LastMediaID', $mediaId);
        }

        return $mediaId;
    }

    /**
     * Internal function to forward requests to LLM
     */
    public function ForwardToLLM(string $SystemPrompt, string $BaseText, string $EventName): string
    {
        $apiKey = $this->ReadPropertyString('OpenAIKey');
        $url = $this->ReadPropertyString('LLM_Base_URL');
        $model = $this->ReadPropertyString('LLM_Model');
        
        if (empty($url)) {
            IPS_LogMessage('VoiceAssistantGateway', 'LLM Base URL is empty.');
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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('ForwardToLLM Error', "HTTP $httpCode - cURL Error: $error - Response: $response", 0);
            IPS_LogMessage('VoiceAssistantGateway', "LLM API Error: $httpCode - $response");
            return '';
        }

        $responseData = json_decode($response, true);
        if (isset($responseData['choices'][0]['message']['content'])) {
            return trim($responseData['choices'][0]['message']['content']);
        }

        return '';
    }

    /**
     * Internal function to forward requests to ElevenLabs v3
     */
    public function ForwardToElevenLabs(string $Text, string $VoiceID, string $ModelID): string
    {
        $apiKey = $this->ReadPropertyString('ElevenLabsKey');
        
        if (empty($apiKey) || empty($VoiceID)) {
            IPS_LogMessage('VoiceAssistantGateway', 'ElevenLabs API Key or Voice ID is empty.');
            return '';
        }

        if (empty($ModelID)) {
             $ModelID = 'eleven_multilingual_v3';
        }

        $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $VoiceID;
        $data = [
            'text' => $Text,
            'model_id' => $ModelID,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'xi-api-key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('ForwardToElevenLabs Error', "HTTP $httpCode - cURL Error: $error - Response: $response", 0);
            IPS_LogMessage('VoiceAssistantGateway', "ElevenLabs API Error: $httpCode - $response");
            return '';
        }

        return $response;
    }
}

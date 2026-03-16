<?php

declare(strict_types=1);

class VoiceGateway extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties for Secrets and Default Routing
        $this->RegisterPropertyString('OpenAIKey', '');
        $this->RegisterPropertyString('ElevenLabsKey', '');
        $this->RegisterPropertyInteger('DefaultCharacter_ID', 0);

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
     * Public Speak function for the Gateway.
     * Routes the request to the DefaultCharacter_ID.
     */
    public function Speak(string $EventName, string $BaseText): int
    {
        $defaultCharacterId = $this->ReadPropertyInteger('DefaultCharacter_ID');

        if ($defaultCharacterId == 0 || !IPS_InstanceExists($defaultCharacterId)) {
            $this->SendDebug('Error', 'No valid default character specified.', 0);
            IPS_LogMessage('VoiceGateway', 'Die Speak-Funktion wurde aufgerufen, aber es ist kein gültiger Standard-Charakter hinterlegt.');
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
     * Internal function to forward requests to LLM (OpenAI)
     */
    public function ForwardToLLM(string $SystemPrompt, string $BaseText, string $EventName): string
    {
        $apiKey = $this->ReadPropertyString('OpenAIKey');
        if (empty($apiKey)) {
            IPS_LogMessage('VoiceGateway', 'OpenAI API Key is empty.');
            return '';
        }

        $url = 'https://api.openai.com/v1/chat/completions';
        $data = [
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $SystemPrompt . "\n\nWICHTIG: Antworte immer mit Text, der ElevenLabs Emotion-Tags in englisch und in eckigen Klammern enthält (z.B. [laughs])."],
                ['role' => 'user', 'content' => "Ereignis: $EventName. Text: $BaseText"]
            ],
            'temperature' => 0.7
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
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
            IPS_LogMessage('VoiceGateway', "LLM API Error: $httpCode - $response");
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
            IPS_LogMessage('VoiceGateway', 'ElevenLabs API Key or Voice ID is empty.');
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
            IPS_LogMessage('VoiceGateway', "ElevenLabs API Error: $httpCode - $response");
            return '';
        }

        return $response;
    }
}

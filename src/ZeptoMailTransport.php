<?php

namespace Brunocfalcao\ZeptoMailApiDriver;

use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;

class ZeptoMailTransport extends Transport
{
    protected $key;

    public function __construct($key)
    {
        $this->key = $key;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $from = $message->getFrom();
        $to = $message->getTo();
        $subject = $message->getSubject();
        $body = $message->getBody();
        // Additional processing for attachments, etc.

        $response = $this->sendViaZeptoMail($from, $to, $subject, $body, $message->getChildren());

        // Parse the response and handle errors

        return $response;
    }

    protected function sendViaZeptoMail($from, $to, $subject, $body, $attachments)
    {
        $endpoint = 'https://api.zeptomail.com/v1.1/email';
        $headers = [
            'Authorization: zoho-enczapikey ' . $this->key,
            'Content-Type: application/json',
        ];

        $postData = [
            'bounce_address' => '', // Set bounce address
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'htmlbody' => $body,
            // Add more fields as needed, such as 'attachments' => $attachments
        ];

        // Handle attachments
        if ($attachments) {
            $postData['attachments'] = $this->processAttachments($attachments);
        }

        $curl = curl_init($endpoint);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }

    protected function processAttachments($attachments)
    {
        // Process the attachments and encode them as needed for ZeptoMail
    }
}

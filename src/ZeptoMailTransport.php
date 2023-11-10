<?php

namespace Brunocfalcao\ZeptoMailApiDriver;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\MessageConverter;

class ZeptoMailTransport extends AbstractTransport
{
    protected $key;

    public function __construct(string $key)
    {
        parent::__construct();

        $this->key = $key;
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }

    protected function doSend(SentMessage $message): void
    {
        $symfonyEmail = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->getPayload($symfonyEmail);

        $this->sendViaZeptoMail($payload);
    }

    protected function getPayload(SymfonyEmail $email): array
    {
        $payload = [
            'from' => [
                'address' => $email->getFrom()[0]->getAddress(),
                'name' => $email->getFrom()[0]->getName(),
            ],
            'to' => array_map(function (Address $address) {
                return [
                    'email_address' => [
                        'address' => $address->getAddress(),
                        'name' => $address->getName(),
                    ],
                ];
            }, $email->getTo()),
            'subject' => $email->getSubject(),
            'htmlbody' => $email->getHtmlBody(),
            'textbody' => $email->getTextBody(),
        ];

        // Attachments processing
        $attachments = $email->getAttachments();
        if ($attachments) {
            $payload['attachments'] = array_map(function ($attachment) {
                /** @var \Symfony\Component\Mime\Part\DataPart $attachment */
                return [
                    'content' => base64_encode($attachment->getBody()),
                    'name' => $attachment->getFilename(),
                    'mime_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                ];
            }, iterator_to_array($attachments));
        }

        return $payload;
    }

    protected function sendViaZeptoMail(array $payload): void
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.zeptomail.com/v1.1/email',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Zoho-enczapikey {$this->key}",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new \Exception('cURL Error #:'.$err);
        }

        $responseBody = json_decode($response, true);
        if (isset($responseBody['error'])) {
            throw new \Exception('Error sending email: '.json_encode($responseBody));
        }
    }
}

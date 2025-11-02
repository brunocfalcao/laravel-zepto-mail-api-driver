<?php

// src/ZeptoMailTransport.php

declare(strict_types=1);

namespace Brunocfalcao\ZeptoMailApiDriver;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\MessageConverter;

/**
 * ZeptoMail transport for Symfony Mailer (Laravel).
 *
 * Implements:
 * - Single emails:      POST /v1.1/email
 * - Batch emails:       POST /v1.1/email/batch
 * - Template emails:    POST /v1.1/email/template
 * - Batch + templates:  POST /v1.1/email/template/batch
 *
 * API fields follow ZeptoMail docs:
 *  - from {address,name}
 *  - to/cc/bcc: [{ email_address: {address,name}, merge_info? }]
 *  - reply_to: [{address,name}]
 *  - subject, htmlbody|textbody
 *  - attachments: [{content|file_cache_key, mime_type, name}]
 *  - inline_images: [{content|file_cache_key, mime_type, cid}]
 *  - track_opens, track_clicks, client_reference, mime_headers
 *  - template_key / template_alias (+ optional bounce_address, merge_info)
 *
 * Batch allows per-recipient merge_info and hides recipients from each other.
 */
final class ZeptoMailTransport extends AbstractTransport
{
    /** ZeptoMail API key (without the 'Zoho-enczapikey ' prefix) */
    protected string $key;

    /** Base API URL (e.g. https://api.zeptomail.com or https://api.zeptomail.eu) */
    protected string $baseUrl;

    /** Laravel HTTP client (fakable) */
    protected HttpFactory $http;

    /** Default request options */
    protected int $timeout;

    protected int $retries;

    protected int $retrySleepMs;

    public function __construct(
        string $key,
        HttpFactory $http,
        ?string $baseUrl = null,
        ?int $timeout = null,
        ?int $retries = null,
        ?int $retrySleepMs = null
    ) {
        parent::__construct();

        $this->key = $key;
        $this->http = $http;
        $this->baseUrl = mb_rtrim($baseUrl ?: (string) config('services.zeptomail.endpoint', 'https://api.zeptomail.com'), '/');
        $this->timeout = (int) ($timeout ?? config('services.zeptomail.timeout', 30));
        $this->retries = (int) ($retries ?? config('services.zeptomail.retries', 2));
        $this->retrySleepMs = (int) ($retrySleepMs ?? config('services.zeptomail.retry_sleep_ms', 200));
    }

    /**
     * Transport name used by Laravel's mailer config.
     */
    public function __toString(): string
    {
        return 'zeptomail';
    }

    /**
     * Entry point called by Symfony Mailer.
     */
    protected function doSend(SentMessage $message): void
    {
        /** @var SymfonyEmail $email */
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        // Decide which API family to call based on presence of template key/alias and batch hints.
        $templateKeyOrAlias = $this->extractTemplateKeyOrAlias($email);  // header X-Zepto-Template or config
        $globalMergeInfo = $this->extractGlobalMergeInfo($email);     // header X-Zepto-MergeInfo (JSON)
        $perRecipientMerge = $this->extractPerRecipientMergeInfo($email); // header X-Zepto-PerRecipient-MergeInfo (JSON map)
        $forceBatch = $this->shouldForceBatch($email, $perRecipientMerge);

        // Build payload following ZeptoMail schemas.
        $payload = $this->buildCommonPayload($email);

        // reply_to is an array of {address,name} (not nested in email_address)
        $replyTo = $this->mapReplyTo($email->getReplyTo());
        if ($replyTo) {
            $payload['reply_to'] = $replyTo;
        }

        // Attachments & inline images (base64 OR file_cache_key)
        [$attachments, $inlineImages] = $this->extractAttachments($email);
        if ($attachments) {
            $payload['attachments'] = $attachments;
        }
        if ($inlineImages) {
            $payload['inline_images'] = $inlineImages;
        }

        // Tracking flags and client reference (by headers or config)
        $this->applyFlagsAndReference($email, $payload);

        // mime_headers: pass-through custom headers as name => value object
        $custom = $this->extractCustomHeaders($email);
        if ($custom) {
            $payload['mime_headers'] = $custom;
        }

        // Addressing: to/cc/bcc arrays in Zepto format
        // For batch, allow per-recipient merge_info; for single, plain list.
        $to = $this->mapToWithOptionalMerge($email->getTo(), $forceBatch ? $perRecipientMerge : []);
        $cc = $this->mapAddresses($email->getCc());
        $bcc = $this->mapAddresses($email->getBcc());
        if ($to) {
            $payload['to'] = $to;
        }
        if ($cc) {
            $payload['cc'] = $cc;
        }
        if ($bcc) {
            $payload['bcc'] = $bcc;
        }

        // If using template APIs
        if ($templateKeyOrAlias) {
            $payload = array_merge($payload, $templateKeyOrAlias);

            // Template global merge_info (optional)
            if ($globalMergeInfo) {
                $payload['merge_info'] = $globalMergeInfo;
            }

            // Optional bounce address via header X-Zepto-Bounce-Address or config (templates API supports it)
            if ($bounce = $this->extractBounceAddress($email)) {
                $payload['bounce_address'] = $bounce;
            }

            $endpoint = $forceBatch
                ? '/v1.1/email/template/batch'   // batch templates
                : '/v1.1/email/template';        // single template

            $response = $this->postToZepto($endpoint, $payload);
            $this->storeResponseInMessage($message, $response);

            return;
        }

        // Non-template APIs
        $endpoint = $forceBatch
            ? '/v1.1/email/batch'   // batch sending (supports per-recipient merge_info in "to")
            : '/v1.1/email';        // single email (all recipients visible to each other)

        $response = $this->postToZepto($endpoint, $payload);
        $this->storeResponseInMessage($message, $response);
    }

    /**
     * Build fields common to all four endpoints.
     */
    protected function buildCommonPayload(SymfonyEmail $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        return array_filter([
            'from' => $from ? array_filter([
                'address' => $from->getAddress(),
                'name' => $from->getName(),
            ], static fn ($v) => ! is_null($v) && $v !== '') : null,
            'subject' => $email->getSubject(),
            // Zepto requires either htmlbody OR textbody (one of them). We pass both if available.
            'htmlbody' => $email->getHtmlBody(),
            'textbody' => $email->getTextBody(),
        ], static fn ($v) => ! is_null($v) && $v !== '');
    }

    /**
     * Map standard recipients to Zepto format:
     *   [{ "email_address": { "address": "...", "name": "..." } }]
     */
    protected function mapAddresses(array $addresses): array
    {
        return array_values(array_map(static function (Address $a) {
            return [
                'email_address' => array_filter([
                    'address' => $a->getAddress(),
                    'name' => $a->getName(),
                ], static fn ($v) => ! is_null($v) && $v !== ''),
            ];
        }, $addresses));
    }

    /**
     * For batch endpoints, allow per-recipient merge_info:
     *   [{ email_address: {...}, merge_info: {...}? }]
     * Map merge map by recipient email address.
     */
    protected function mapToWithOptionalMerge(array $addresses, array $perRecipientMerge): array
    {
        return array_values(array_map(function (Address $a) use ($perRecipientMerge) {
            $item = [
                'email_address' => array_filter([
                    'address' => $a->getAddress(),
                    'name' => $a->getName(),
                ], static fn ($v) => ! is_null($v) && $v !== ''),
            ];

            if (isset($perRecipientMerge[$a->getAddress()]) && is_array($perRecipientMerge[$a->getAddress()])) {
                $item['merge_info'] = $perRecipientMerge[$a->getAddress()];
            }

            return $item;
        }, $addresses));
    }

    /**
     * reply_to: array of {address,name} (not wrapped in email_address).
     */
    protected function mapReplyTo(array $replyTo): array
    {
        return array_values(array_map(static function (Address $a) {
            return array_filter([
                'address' => $a->getAddress(),
                'name' => $a->getName(),
            ], static fn ($v) => ! is_null($v) && $v !== '');
        }, $replyTo));
    }

    /**
     * Extract attachments and inline (CID) images per Zepto spec.
     */
    protected function extractAttachments(SymfonyEmail $email): array
    {
        $attachments = [];
        $inlineImages = [];

        foreach (iterator_to_array($email->getAttachments()) as $part) {
            /** @var \Symfony\Component\Mime\Part\DataPart $part */
            $filename = method_exists($part, 'getFilename') ? $part->getFilename() : null;
            $mimeType = $part->getMediaType().'/'.$part->getMediaSubtype();
            $raw = (string) $part->getBody();
            $b64 = base64_encode($raw);

            $headers = $part->getPreparedHeaders();
            $cd = $headers->get('Content-Disposition');
            $cidH = $headers->get('Content-ID');

            $isInline = false;
            if ($cd && Str::contains(mb_strtolower((string) $cd->getBodyAsString()), 'inline')) {
                $isInline = true;
            }

            $cid = null;
            if ($cidH) {
                $cid = mb_trim((string) $cidH->getBodyAsString(), '<>');
                $isInline = true;
            }

            $payload = array_filter([
                'content' => $b64,
                'mime_type' => $mimeType,
                'name' => $filename,
            ], fn ($v) => ! is_null($v) && $v !== '');

            if ($isInline) {
                if ($cid) {
                    $payload['cid'] = $cid; // referenced as <img src="cid:...">
                }
                $inlineImages[] = $payload;
            } else {
                $attachments[] = $payload;
            }
        }

        return [$attachments, $inlineImages];
    }

    /**
     * Pull template_key or template_alias from headers or config.
     * Supported headers:
     *   - X-Zepto-Template: (string) key or alias
     * Config fallback: services.zeptomail.template_key or template_alias.
     */
    protected function extractTemplateKeyOrAlias(SymfonyEmail $email): ?array
    {
        $h = $email->getHeaders();
        if ($h->has('X-Zepto-Template')) {
            $val = mb_trim((string) $h->get('X-Zepto-Template')->getBodyAsString());
            if ($val !== '') {
                // Allow users to pass either a key or an alias; Zepto accepts either field name.
                $field = Str::startsWith($val, 'ea') ? 'template_key' : 'template_alias';

                return [$field => $val];
            }
        }

        $key = (string) (config('services.zeptomail.template_key') ?? '');
        $alias = (string) (config('services.zeptomail.template_alias') ?? '');

        if ($key !== '') {
            return ['template_key' => $key];
        }
        if ($alias !== '') {
            return ['template_alias' => $alias];
        }

        return null;
    }

    /**
     * Global merge variables (applies to email outside per-recipient merges).
     * Header: X-Zepto-MergeInfo: JSON object
     */
    protected function extractGlobalMergeInfo(SymfonyEmail $email): ?array
    {
        $h = $email->getHeaders();
        if (! $h->has('X-Zepto-MergeInfo')) {
            return null;
        }

        $json = (string) $h->get('X-Zepto-MergeInfo')->getBodyAsString();
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Per-recipient merge map:
     * Header: X-Zepto-PerRecipient-MergeInfo: JSON object mapping
     *   { "alice@example.com": {...}, "bob@example.com": {...} }
     */
    protected function extractPerRecipientMergeInfo(SymfonyEmail $email): array
    {
        $h = $email->getHeaders();
        if (! $h->has('X-Zepto-PerRecipient-MergeInfo')) {
            return [];
        }

        $json = (string) $h->get('X-Zepto-PerRecipient-MergeInfo')->getBodyAsString();
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Optional bounce address (templates API supports it).
     * Header: X-Zepto-Bounce-Address
     * Config: services.zeptomail.bounce_address
     */
    protected function extractBounceAddress(SymfonyEmail $email): ?string
    {
        $h = $email->getHeaders();
        if ($h->has('X-Zepto-Bounce-Address')) {
            $val = mb_trim((string) $h->get('X-Zepto-Bounce-Address')->getBodyAsString());
            if ($val !== '') {
                return $val;
            }
        }

        $cfg = (string) (config('services.zeptomail.bounce_address') ?? '');

        return $cfg !== '' ? $cfg : null;
    }

    /**
     * Decide when to use batch:
     *  - Header X-Zepto-Batch: true|1
     *  - Config services.zeptomail.force_batch = true
     *  - Presence of per-recipient merge info (required for per-recipient personalization)
     */
    protected function shouldForceBatch(SymfonyEmail $email, array $perRecipientMerge): bool
    {
        $h = $email->getHeaders();
        if ($h->has('X-Zepto-Batch')) {
            $val = mb_strtolower(mb_trim((string) $h->get('X-Zepto-Batch')->getBodyAsString()));
            if (in_array($val, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        if (config('services.zeptomail.force_batch')) {
            return true;
        }

        return ! empty($perRecipientMerge);
    }

    /**
     * Apply tracking flags and client reference from headers or config.
     * Headers:
     *  - X-Zepto-Track-Opens: true|false
     *  - X-Zepto-Track-Clicks: true|false
     *  - X-Zepto-Client-Reference: string
     */
    protected function applyFlagsAndReference(SymfonyEmail $email, array &$payload): void
    {
        $h = $email->getHeaders();

        $trackOpens = $h->has('X-Zepto-Track-Opens') ? filter_var((string) $h->get('X-Zepto-Track-Opens')->getBodyAsString(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $trackClicks = $h->has('X-Zepto-Track-Clicks') ? filter_var((string) $h->get('X-Zepto-Track-Clicks')->getBodyAsString(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $clientRef = $h->has('X-Zepto-Client-Reference') ? mb_trim((string) $h->get('X-Zepto-Client-Reference')->getBodyAsString()) : null;

        if (is_null($trackOpens)) {
            $trackOpens = config('services.zeptomail.track_opens');
        }
        if (is_null($trackClicks)) {
            $trackClicks = config('services.zeptomail.track_clicks');
        }
        if (empty($clientRef)) {
            $clientRef = config('services.zeptomail.client_reference');
        }

        if (! is_null($trackOpens)) {
            $payload['track_opens'] = (bool) $trackOpens;
        }
        if (! is_null($trackClicks)) {
            $payload['track_clicks'] = (bool) $trackClicks;
        }
        if (! empty($clientRef)) {
            $payload['client_reference'] = (string) $clientRef;
        }
    }

    /**
     * Extract custom headers to send as mime_headers (name => value).
     * Excludes standard addressing and our control headers.
     */
    protected function extractCustomHeaders(SymfonyEmail $email): array
    {
        $exclude = [
            'From', 'To', 'Cc', 'Bcc', 'Reply-To', 'Subject',
            'MIME-Version', 'Content-Type', 'Content-Transfer-Encoding',
            // our control headers
            'X-Zepto-Template', 'X-Zepto-MergeInfo', 'X-Zepto-PerRecipient-MergeInfo',
            'X-Zepto-Batch', 'X-Zepto-Track-Opens', 'X-Zepto-Track-Clicks', 'X-Zepto-Client-Reference',
            'X-Zepto-Bounce-Address',
        ];

        $pairs = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $val = mb_trim((string) $header->getBodyAsString());
            if ($val === '') {
                continue;
            }
            if (isset($pairs[$name])) {
                $pairs[$name] .= ', '.$val;
            } else {
                $pairs[$name] = $val;
            }
        }

        return $pairs;
    }

    /**
     * Perform the POST using Laravel's HTTP client (fakable).
     * Throws on non-2xx; also throws if Zepto returns an "error" object.
     * Returns the response body and request headers for tracking purposes.
     */
    protected function postToZepto(string $path, array $payload): array
    {
        // Build headers to send
        $requestHeaders = [
            'Authorization' => 'Zoho-enczapikey '.$this->key,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $response = $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->withHeaders($requestHeaders)
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retrySleepMs)
            ->post($path, $payload);

        $response->throw();

        $body = $response->json();
        if (is_array($body) && Arr::has($body, 'error')) {
            throw new RuntimeException('Error sending email: '.json_encode($body));
        }

        // Return both response body and request headers for tracking
        return [
            'response' => is_array($body) ? $body : [],
            'request_headers' => $requestHeaders,
        ];
    }

    /**
     * Store the Zeptomail API response and request headers in the SentMessage for Laravel's notification system to access.
     * This allows the NotificationLogListener to extract the message_id and request headers for tracking.
     */
    protected function storeResponseInMessage(SentMessage $message, array $data): void
    {
        // Store the full Zeptomail response and request headers in the SentMessage's metadata
        // Laravel's mail system will make this available via the NotificationSent event
        $original = $message->getOriginalMessage();
        if (method_exists($original, 'getHeaders')) {
            $headers = $original->getHeaders();

            // Store response body
            if (isset($data['response'])) {
                $headers->addTextHeader('X-Zepto-Response', json_encode($data['response']));
            }

            // Store request headers sent to Zeptomail API
            if (isset($data['request_headers'])) {
                $headers->addTextHeader('X-Zepto-Request-Headers', json_encode($data['request_headers']));
            }
        }
    }
}

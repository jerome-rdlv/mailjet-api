<?php

namespace Rdlv\WordPress\MailjetApi;

use Exception;
use Mailjet\Client;
use Mailjet\Resources;
use PHPMailer\PHPMailer\PHPMailer;

class Message extends PHPMailer
{
    public static function create(string $publicKey, string $privateKey): self
    {
        $instance = new self(true);
        $instance->mailjetApiClient = new Client($publicKey, $privateKey, true, ['version' => 'v3.1']);
        return $instance;
    }

    /** @var Client */
    private $mailjetApiClient;

    private function format_address(array $address): array
    {
        return [
            'Email' => $address[0],
            'Name' => $address[1],
        ];
    }

    private function format_attachments($disposition)
    {
        return array_map(
            function ($attachment) {
                return [
                    "ContentType" => $attachment[4],
                    "Filename" => $attachment[2],
                    "Base64Content" => base64_encode(
                        $attachment[5] ? $attachment[0] : file_get_contents($attachment[0])
                    ),
                ];
            },
            array_filter($this->attachment, function ($attachment) use ($disposition) {
                return $attachment[6] === $disposition;
            })
        );
    }

    public function send()
    {
        $message = array_filter(
            [
                'From' => $this->format_address([$this->From, $this->FromName]),
                'To' => array_map([$this, 'format_address'], $this->to),
                'Cc' => array_map([$this, 'format_address'], $this->cc),
                'Bcc' => array_map([$this, 'format_address'], $this->bcc),
                'Subject' => $this->Subject,
                'ContentType' => $this->ContentType,
                'Attachments' => $this->format_attachments('attachment'),
                'InlinedAttachments' => $this->format_attachments('inline'),
            ]
        );

        if ($this->ReplyTo) {
            $replyToHeaders = array_values($this->ReplyTo);
            if (count($replyToHeaders) > 1) {
                trigger_error('Only a single ReplyTo header is supported', E_USER_WARNING);
            }
            $message['ReplyTo'] = $this->format_address($replyToHeaders[0]);
        }

        $message[$this->ContentType === PHPMailer::CONTENT_TYPE_TEXT_HTML ? 'HTMLPart' : 'TextPart'] = $this->Body;

        $response = $this->mailjetApiClient->post(Resources::$Email, [
            'body' => [
                'Messages' => [$message],
            ],
        ]);

        if ($response->success()) {
            return true;
        }

        $error = json_encode(['response' => $response->getBody(), 'message' => $message]);
        trigger_error($error, E_USER_WARNING);
        throw new \PHPMailer\PHPMailer\Exception(sprintf('MailJet Error: %s', $error));
    }
}
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BrandedNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var array<string, mixed> */
    public readonly array $branding;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $headingText,
        public readonly string $bodyText,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
        ?Tenant $tenant = null,
        ?User $recipient = null,
    ) {
        $this->branding = app(BrandingService::class)->toPublicPayload($recipient, $tenant);
    }

    public function envelope(): Envelope
    {
        $orgName = is_string($this->branding['name'] ?? null) ? $this->branding['name'] : 'BetterDoc DMS';

        return new Envelope(
            subject: "[{$orgName}] {$this->subjectLine}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.branded',
            with: [
                'branding' => $this->branding,
                'headingText' => $this->headingText,
                'bodyText' => $this->bodyText,
                'actionLabel' => $this->actionLabel,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}

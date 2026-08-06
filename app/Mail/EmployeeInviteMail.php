<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Приглашение в ERP',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-invite',
            with: [
                'employee' => $this->employee,
                'inviteUrl' => route('invite.show', $this->employee->invite_token),
            ],
        );
    }
}

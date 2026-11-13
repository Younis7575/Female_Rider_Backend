<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    /** Hidden field that humans never fill in; bots usually do. */
    public const HONEYPOT = 'company_website';

    public const SUBJECTS = [
        'general' => 'General question',
        'rider' => 'Rider support',
        'driver' => 'Becoming a driver',
        'safety' => 'Safety concern',
        'partnership' => 'Partnerships & press',
        'privacy' => 'Privacy / data request',
    ];

    public function show(): View
    {
        return view('website.pages.contact', [
            'subjects' => self::SUBJECTS,
            'honeypot' => self::HONEYPOT,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $success = 'Thank you — your message has been received. Our team replies during support hours.';

        // Honeypot tripped: pretend success so bots learn nothing, but store nothing.
        if (filled($request->input(self::HONEYPOT))) {
            return redirect()->route('website.contact')->with('status', $success);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:20', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'subject' => ['required', 'string', 'in:'.implode(',', array_keys(self::SUBJECTS))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'email.required_without' => 'Please give us an email address or a phone number so we can reply.',
            'phone.required_without' => 'Please give us a phone number or an email address so we can reply.',
            'phone.regex' => 'Please enter a valid phone number.',
        ]);

        ContactMessage::query()->create([
            'name' => strip_tags($data['name']),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'subject' => self::SUBJECTS[$data['subject']],
            'message' => strip_tags($data['message']),
            'status' => 'new',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('website.contact')->with('status', $success);
    }
}

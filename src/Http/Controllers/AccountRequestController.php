<?php

namespace Inovector\Mixpost\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Inovector\Mixpost\Http\Requests\StoreAccountRequest;
use Inovector\Mixpost\Mail\AccountRequestMail;
use RuntimeException;
use Throwable;

class AccountRequestController extends Controller
{
    public function __invoke(StoreAccountRequest $request): RedirectResponse
    {
        $recipient = config('mixpost.account_request_email');

        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            report(new RuntimeException('Mixpost account request email is not configured.'));

            return redirect()->route('mixpost.home')
                ->withInput()
                ->with('account_request_error', 'We couldn’t send your request. Please try again later.');
        }

        try {
            Mail::to($recipient)->send(new AccountRequestMail($request->string('email')->toString()));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('mixpost.home')
                ->withInput()
                ->with('account_request_error', 'We couldn’t send your request. Please try again later.');
        }

        return redirect()->route('mixpost.home')->with('account_request_sent', true);
    }
}

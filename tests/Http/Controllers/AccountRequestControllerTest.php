<?php

use Illuminate\Support\Facades\Mail;
use Inovector\Mixpost\Mail\AccountRequestMail;

beforeEach(function () {
    Mail::fake();
    config()->set('mixpost.account_request_email', 'dan@peachyhq.com');
});

it('shows a secure account request form', function () {
    $this->get(route('mixpost.home'))
        ->assertOk()
        ->assertSee('action="'.route('mixpost.account-request', [], false).'"', false)
        ->assertDontSee('mailto:', false);
});

it('sends an account request using a normalized work email', function () {
    $this->post(route('mixpost.account-request'), [
        'email' => '  TANK@PEACHYHQ.COM ',
    ])->assertRedirectToRoute('mixpost.home')
        ->assertSessionHas('account_request_sent', true);

    Mail::assertSent(AccountRequestMail::class, function (AccountRequestMail $mail) {
        return $mail->hasTo('dan@peachyhq.com') && $mail->email === 'tank@peachyhq.com';
    });
});

it('rejects account requests from outside the work domain', function () {
    $this->post(route('mixpost.account-request'), [
        'email' => 'tank@example.com',
    ])->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});

it('shows an inline error when account request delivery is not configured', function () {
    config()->set('mixpost.account_request_email');

    $this->post(route('mixpost.account-request'), [
        'email' => 'tank@peachyhq.com',
    ])->assertRedirectToRoute('mixpost.home')
        ->assertSessionHas('account_request_error');

    Mail::assertNothingSent();
});

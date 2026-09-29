<?php

namespace Inovector\Mixpost\SocialProviders\Threads\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Inovector\Mixpost\Features;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesOAuth
{
    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => implode(',', $this->getSupportedScopeList()),
            'response_type' => 'code',
            'state' => $this->values['state'],
        ];

        return $this->buildUrlFromBase('https://threads.net/oauth/authorize', $params);
    }

    public function getSupportedScopeList(): array
    {
        $scopes = [
            'threads_basic',
            'threads_delete',
            'threads_content_publish',
            'threads_manage_replies',
            'threads_manage_insights',
        ];

        if (Features::canAccessInbox()) {
            $scopes[] = 'threads_read_replies';
        }

        return $scopes;
    }

    public function requestAccessToken(array $params = []): array
    {
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'grant_type' => 'authorization_code',
            'code' => $params['code'],
        ];

        $response = $this->http()::post("$this->graphUrl/oauth/access_token", $params)->json();

        if ($error = $this->tokenExchangeError($response)) {
            return ['error' => $error];
        }

        if (! is_array($response) || empty($response['access_token'])) {
            return ['error' => __('mixpost::error.invalid_grant')];
        }

        return $this->requestLongLivedAccessToken($response['access_token']);
    }

    public function requestLongLivedAccessToken(string $shortLivedAccessToken = ''): array
    {
        $params = [
            'grant_type' => 'th_exchange_token',
            'client_secret' => $this->clientSecret,
            'access_token' => $shortLivedAccessToken ?: $this->getAccessToken()['access_token'],
        ];

        $response = $this->http()::get("$this->graphUrl/access_token", $params)->json();

        if ($error = $this->tokenExchangeError($response)) {
            return ['error' => $error];
        }

        if (! is_array($response) || empty($response['access_token']) || ! isset($response['expires_in'])) {
            return ['error' => __('mixpost::error.invalid_grant')];
        }

        return [
            'access_token' => $response['access_token'],
            'expires_in' => Carbon::now('UTC')->addSeconds((int) $response['expires_in'])->timestamp,
        ];
    }

    public function refreshToken(?string $refreshToken = null): SocialProviderResponse
    {
        $params = [
            'grant_type' => 'th_refresh_token',
            'access_token' => $refreshToken ?: $this->getAccessToken()['access_token'],
        ];

        $response = $this->http()::get("$this->graphUrl/refresh_access_token", $params);

        return $this->buildResponse($response, function () use ($response) {
            $data = $response->json();

            return [
                'access_token' => $data['access_token'],
                'expires_in' => Carbon::now('UTC')->addSeconds($data['expires_in'])->timestamp,
            ];
        });
    }

    protected function tokenExchangeError(mixed $response): ?string
    {
        if (! is_array($response)) {
            return __('mixpost::error.invalid_grant');
        }

        $message = Arr::get($response, 'error.message') ?? Arr::get($response, 'error_message');

        if (! is_string($message) || $message === '') {
            return null;
        }

        $code = Arr::get($response, 'error.code') ?? Arr::get($response, 'error_code');

        return $code === null ? $message : "$message (Meta code $code)";
    }
}

<?php

namespace App\Security\OAuth;

use Aego\OAuth2\Client\Provider\Yandex;
use KnpU\OAuth2ClientBundle\Client\Provider\YandexDecorator;
use League\OAuth2\Client\Provider\AbstractProvider;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;

class YandexProvider extends OAuth2Client
{
    public function __construct(
        AbstractProvider $provider,
        string $redirectUri,
        array $guzzleOptions = []
    ) {
        parent::__construct($provider, $redirectUri, $guzzleOptions);
    }

    /**
     * @return Yandex
     */
    public function getOAuth2Provider(): AbstractProvider
    {
        return $this->getOAuth2Provider();
    }
}

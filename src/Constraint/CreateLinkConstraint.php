<?php

declare(strict_types=1);

namespace App\Constraint;

use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints as Assert;

class CreateLinkConstraint
{
    private Collection $constraint;

    public function __construct()
    {
        $this->constraint = new Assert\Collection([
            'url' => [
                new Assert\NotBlank(message: 'Пожалуйста, введите ссылку'),
                new Assert\Url(message: 'Пожалуйста, введите корректный URL'),
            ],
            'customAlias' => [
                new Assert\Type('string'),
                new Assert\Length(
                    max: 64,
                    maxMessage:  'Алиас не должен превышать 64 символов',
                ),
                new Assert\Regex(
                    pattern:  '/^[a-zA-Z0-9_-]+$/',
                    message:  'Алиас может содержать только буквы, цифры, дефисы и подчеркивания',
                ),
            ],
            'expires_at' => new Assert\Collection([
                new Assert\DateTime(),
                new Assert\GreaterThan('now')
            ]),
            'password' => new Assert\Collection([
                new Assert\Type(type: 'string'),
//                new Assert\NoRequirement(),
            ]),
        ]);
    }

    public function getConstraint(): Collection
    {
        return $this->constraint;
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ShortUrl;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

class ShortUrlType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('longUrl', UrlType::class, [
                'label' => 'Длинная ссылка',
                'attr' => [
                    'placeholder' => 'https://example.com/very-long-url',
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Пожалуйста, введите ссылку']),
                    new Url(['message' => 'Пожалуйста, введите корректный URL']),
                ],
            ])
            ->add('customAlias', TextType::class, [
                'label' => 'Пользовательский алиас (необязательно)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'my-custom-link',
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new Length([
                        'max' => 64,
                        'maxMessage' => 'Алиас не должен превышать 64 символов',
                    ]),
                    new Regex([
                        'pattern' => '/^[a-zA-Z0-9_-]+$/',
                        'message' => 'Алиас может содержать только буквы, цифры, дефисы и подчеркивания',
                    ]),
                ],
            ])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Срок действия (необязательно)',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Активная ссылка',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input',
                ],
                'data' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShortUrl::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'short_url',
        ]);
    }
}

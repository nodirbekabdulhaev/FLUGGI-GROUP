<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Нарушение бизнес-правила (понятное пользователю сообщение). Страница → назад с сообщением
 * и ошибками полей; JSON → 422 {message, errors}. throw new BusinessRule(t('deals.errors.noProposal'), ['amount' => '…']).
 */
class BusinessRule extends RuntimeException
{
    /** @param  array<string,string>  $fields  поле → сообщение */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}

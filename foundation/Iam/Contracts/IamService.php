<?php

declare(strict_types=1);

namespace Foundation\Iam\Contracts;

interface IamService
{
    /** @return array{id: int, name: string}|null */
    public function findUser(int $id): ?array;

    /** @return array{id: int, name: string}|null */
    public function findUserByToken(string $token): ?array;

    /** @return list<string> the permissions the user holds, through its roles or directly */
    public function grants(int $userId): array;
}
